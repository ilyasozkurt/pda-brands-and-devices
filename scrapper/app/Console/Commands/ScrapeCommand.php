<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ScrapeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:pdas';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This function scrapes mobile phone manufacturers and their devices from gsmarena.com';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $lastIndex = (int)file_get_contents('last_index.txt') - 1;
        $lastHash = (string)file_get_contents('last_hash.txt');

        $sitemapSource = Http::get('https://phonedb.net/sitemap/');

        if ($lastHash !== md5($sitemapSource->body())) {
            $lastIndex = 0;
            $lastHash = md5($sitemapSource->body());
            file_put_contents('last_hash.txt', $lastHash);
        }

        $xml = simplexml_load_string($sitemapSource->body());

        $progressbar = $this->output->createProgressBar(count($xml->url));
        $progressbar->start();

        $index = 0;

        foreach ($xml->url as $url) {

            if ($index < $lastIndex) {
                $index++;
                $progressbar->advance();
                continue;
            }

            if (strpos($url->loc, 'm=device&id=')) {

                $device = Device::where('url_hash', md5($url->loc))
                    ->get();

                if (!$device->count()) {

                    try {

                        $httpSource = Http::get($url->loc . '&d=detailed_specs');

                        $parser = str_get_html($httpSource->body());

                        $data = [];

                        $data['Picture'] = @$parser->find('.sidebar img', 0)->src;
                        $data['Picture'] = $data['Picture'] ? 'https://phonedb.net/' . $data['Picture'] : '';

                        foreach ($parser->find('.canvas table tr') as $row) {

                            $columns = $row->find('td');

                            if (count($columns) === 2) {

                                $label = trim(html_entity_decode($columns[0]->plaintext));
                                $value = trim(html_entity_decode($columns[1]->plaintext));
                                $value = str_replace(' , ', ', ', $value);

                                $data[$label] = $value;

                            }

                        }

                        if (count($data)) {

                            $brand = Brand::firstOrCreate([
                                'name' => $data['Brand']
                            ]);

                            $specificationsDom = $parser->find('#specs-list table tr');

                            $specifications = [];

                            foreach ($specificationsDom as $row) {
                                $ttl = $row->find('.ttl')[0]->plaintext ?? null;
                                $info = $row->find('.nfo')[0]->plaintext ?? null;
                                if ($ttl) {
                                    $specifications[$ttl] = $info;
                                }
                            }

                            $device = Device::firstOrCreate([
                                'url_hash' => md5($url->loc)
                            ], [
                                'url_hash' => md5($url->loc),
                                'brand_id' => $brand->id,
                                'name' => $data['Model'],
                                'picture' => $data['Picture'],
                                'released_at' => $data['Released'],
                                'specifications' => $data,
                            ]);

                            $device->save();

                        }

                    }catch (\Exception $exception){

                    }

                }

            }

            file_put_contents('last_index.txt', $index);

            $progressbar->advance();

            $index++;

        }

        $progressbar->finish();

        return 0;

    }
}
