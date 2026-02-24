<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use OpenAPI\Client\Configuration;
use OpenAPI\Client\Api\BackupServiceApi;
use GuzzleHttp\Client as GuzzleClient;
use Exception;

// VDSINa API
// f0098fd92dc86be2c2d915365c5b99903fc1485ed4e892de07e8298595b47ed6


class GetAccountCommand extends Command
{
    public $client;
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'account';

    /**
     * The console command description.
     */
    protected $description = 'Get available backup copies from BackupService API';

    /**
     * Execute the console command.
     */

    public function auth()
    {
        $client = new Client([
            'base_uri' => 'https://api.beget.com/',
            'headers' => [
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
        ]);

        $request = $client->post('/v1/auth', [
            'json' => [
                'login' => 'origamiv',
                'password' => 'Ori_9030404',
            ]
        ]);
        $response = json_decode($request->getBody()->getContents());
        $token = $response->token;

        dump($token);

        $this->client = new Client([
            'base_uri' => 'https://api.beget.com/',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
        ]);
    }

    public function handle(): int
    {

        $this->auth();

        $request = $this->client->get('/v1/vps/region');
        $response = json_decode($request->getBody()->getContents(), true)['regions'];

        $r = Arr::keyBy($response, 'country');
        unset($r['RU']);

//        $request=$this->client->get('/v1/vps/configuration');
//        $response = json_decode($request->getBody()->getContents(), true)['configurations'];
//        //$r=Arr::keyBy($response, 'region');
//        $prices=[];
//        foreach ($response as $key=>$item) {
//            if ($item['region']=='ru1' || $item['region']=='ru2') {
//                unset($response[$key]);
//            }
//            else {
//                $price = $item['price_day'];
//                $prices[$price][] = $item;
//            }
//        }
//        ksort($prices);
//        dd($prices);


//        $request=$this->client->get('/v1/vps/configurator/calculation', [
//            'json' => [
//                'params.cpu_count'=>4,
//                'params.memory' =>8*1024,
//                'params.disk_size'=>40*1024,
//                'region'=>'lv1'
//            ]
//        ]);
//        $response = json_decode($request->getBody()->getContents(), true);
//        dd($response);

        try {
            $request = $this->client->post('/v1/vps/server', [
                'data' => [
                    'display_name' => 'dev_' . date('Y_m_d'),
                    'hostname' => 'cloud_e',

                    'description' => 'Dev VPS created from API',

                    'configuration_group' => 'normal_cpu',
                    'configuration_params' => [
                        'cpu_count' => 4,
                        'memory'    => 8*1024,   // MB
                        'disk_size' => 40 * 1024,  // MB
                    ],

                    // источник диска
                    //'image_id' => 'ubuntu-22.04',

                    // ПО (опционально)
//                    'software' => [
//                        'id' => 1,
//                        'variable' => [
//                            'timezone' => 'UTC',
//                        ],
//                    ],

                    // доступ
                    'password' => 'Ori9030404',
                    'ssh_keys' => [],
                    'beget_ssh_access_allowed' => true,

                    // сети (опционально)
//                    'private_networks' => [
//                        [
//                            'id' => 'network-id-uuid',
//                            'address' => '',
//                        ],
//                    ],

                    // доп. параметры
                    //'region' => 'lv1',
//                    'ui_pinned' => true,
//                    'link_slug' => 'dev-cloud',
//                    'project_id' => '550e8400-e29b-41d4-a716-446655440000',
                ],
            ]);

            $response = json_decode($request->getBody()->getContents(), true);
            dd($response);
        }
        catch (Exception $e) {
            dd($e->getMessage());
        }

        return 1;

    }
}
