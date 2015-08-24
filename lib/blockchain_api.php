<?php

interface blockchain_api {
    public function get_addresses_transactions( $addr_list, $params );
}

class blockchain_api_factory {
    static public function instance( $type ) {
        $type = trim($type);
        $class = 'blockchain_api_' . $type;
        try {
            return new $class;
        }
        catch ( Exception $e ) {
            throw new Exception( "Invalid api provider '$type'" );
        }
    }
}

class blockchain_api_toshi  {
    
    public function get_addresses_transactions( $addr_list, $params ) {
        $tx_list = array();
        foreach( $addr_list as $addr ) {
            $tx_list_new = $this->get_address_transactions( $addr, $params );
            $tx_list = array_merge( $tx_list, $tx_list_new );
        }
        return $tx_list;
    }
    
    private function get_address_transactions( $addr, $params ) {
        
        $tx_method = $params['toshi-fast'] ? 'transactionsfiltered' : 'transactions';
        $addr_tx_limit = $params['addr-tx-limit'];
        
        $url_mask = "%s/api/v0/addresses/%s/%s?limit=%s";
        $url = sprintf( $url_mask, $params['toshi'], $addr, $tx_method, $addr_tx_limit );
        
        mylogger()->log( "Retrieving transactions from $url", mylogger::info );
        
        // Todo:  make more robust with timeout, retries, etc.
        $buf = @file_get_contents( $url );
file_put_contents( '/tmp/toshi.json', $buf );
        
        // note: http_response_header is set by file_get_contents.
        // next line will throw exception wth code 1001 if response code not found.
        $server_http_code = httputil::http_response_header_http_code( @$http_response_header );
        
        if( $server_http_code == 404 ) {
            return array();
        }
        else if( $server_http_code != 200 ) {
            throw new Exception( "Got unexpected response code $server_http_code" );
        }
        
        $data = json_decode( $buf, true );
        $tx_list = $data['transactions'];
        
        return $this->normalize_transactions( $tx_list, $addr );
    }
    
    protected function normalize_transactions( $tx_list_toshi, $addr ) {
//print_r( $tx_list_toshi );        
        $tx_list_normal = array();
        foreach( $tx_list_toshi as $tx_toshi ) {
            $amount_in = 0;
            $amount_out = 0;
            $amount = 0;
            
            $idx = 0;
            foreach( $tx_toshi['inputs'] as $input ) {
                $idx ++;
                
                // $input['addresses] can be empty, eg for a coinbase transaction
                //    $input['coinbase'] will be set instead.
                if( !@count( @$input['addresses'] ) ) {
                    continue;
                }
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( @count( $input['addresses'] ) != 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in input #%s, transaction %s.  skipping input.", @count( $input['addresses'] ), $idx, $tx_toshi['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                if( $input['addresses'][0] == $addr ) {
                    $amount_out = $amount_out + $input['amount'];
                }
            }
            $idx = 0;
            $understood = array( 'p2sh', 'hash160', 'pubkey' );
            foreach( $tx_toshi['outputs'] as $output ) {
                $idx ++;
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( count( $output['addresses'] ) != 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in output #%, transaction %s.  skipping output.", count( $output['addresses'] ), $idx, $tx_toshi['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                // script_type can be hash160, p2sh or multisig.
                // If we include multisig in balance calcs, then our balances do not match with bitcoind.
                // So for now we include hash160 and p2sh only.
                // See also: https://github.com/coinbase/toshi/issues/189
                if( $output['addresses'][0] == $addr && in_array( $output['script_type'], $understood ) ) {
                    $amount_in = $amount_in + $output['amount'];
                }
            }
            $amount = $amount_in - $amount_out;
                        
            $tx_list_normal[] = array( 'block_time' => strtotime( $tx_toshi['block_time'] ),
                                       'addr' => $addr,
                                       'amount' => $amount,
                                       'amount_in' => $amount_in,
                                       'amount_out' => $amount_out,
                                       'txid' => $tx_toshi['hash'],
                                     );
            
        }
        
        return $tx_list_normal;
    }
    
}


class blockchain_api_btcd implements blockchain_api {
    
    public function get_addresses_transactions( $addr_list, $params ) {
        $tx_list = array();
        foreach( $addr_list as $addr ) {
            $tx_list_new = $this->get_address_transactions( $addr, $params );
            $tx_list = array_merge( $tx_list, $tx_list_new );
        }
        return $tx_list;
    }
    
    private function get_address_transactions( $addr, $params ) {

        $btcd = sprintf( '%s:%s', $params['btcd-rpc-host'], $params['btcd-rpc-port'] );
        mylogger()->log( "Retrieving transactions from btcd $btcd", mylogger::info );
        
        $url = sprintf( 'http://%s:%s@%s:%s/', $params['btcd-rpc-user'], $params['btcd-rpc-pass'],
                                          $params['btcd-rpc-host'], $params['btcd-rpc-port']);
        // echo $url . "\n";
        $rpc = new BitcoinClient( $url, false, 'BTC' );
        
        $tx_list = $rpc->searchrawtransactions( $addr, $verbose=1, $skip=0, $count=1000000, $vinExtra=1, $filterAddr=1 );
        mylogger()->log( "Received transactions from btcd.", mylogger::info );

//        print_r( $tx_list );  exit;
//        exit;
        
        return $this->normalize_transactions( $tx_list, $addr );
    }
    
    protected function normalize_transactions( $tx_list_btcd, $addr ) {
//print_r( $tx_list_btcd );        
        $tx_list_normal = array();
        foreach( $tx_list_btcd as $tx_btcd ) {
            $amount_in = 0;
            $amount_out = 0;
            $amount = 0;
            
            $idx = 0;
            foreach( $tx_btcd['vin'] as $input ) {
                $idx ++;
                
                // note: at this time, prevOut requires patched btcd from
                // https://github.com/dan-da/btcd
                $prevOut = @$input['prevOut'];
                if( !$prevOut ) {
                    continue;
                }
                
                // $prevOut['addresses] can be empty, eg for a coinbase transaction
                //    $input['coinbase'] will be set instead.
                
                $addresses = @$prevOut['addresses'];
                if( !@count( $addresses ) ) {
                    continue;
                }
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26                
                if( @count( $addresses ) != 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in input #%s, transaction %s.  skipping input.", count( $addresses ), $idx, $tx_btcd['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                if( $addresses[0] == $addr ) {
                    $amount_out = $amount_out + btcutil::btc_to_int( $prevOut['value'] );
                }
            }
            $idx = 0;
            $not_understood = array( 'multisig' );
            foreach( $tx_btcd['vout'] as $output ) {
//print_r( $output ); exit;
                $idx ++;
                $addresses = @$output['scriptPubKey']['addresses'];
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26                
                if( @count( $addresses ) != 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in output #%, transaction %s.  skipping output.", @count( $addresses ), $idx, @$tx_btcd['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                // script_type can be hash160, p2sh or multisig.
                // If we include multisig in balance calcs, then our balances do not match with bitcoind.
                // So for now we include hash160 and p2sh only.
                // See also: https://github.com/coinbase/btcd/issues/189
                if( $addresses[0] == $addr && !in_array( $output['scriptPubKey']['type'], $not_understood ) ) {
                    $amount_in = $amount_in + btcutil::btc_to_int( $output['value'] );
                }
            }
            $amount = $amount_in - $amount_out;

            $tx_list_normal[] = array( 'block_time' => $tx_btcd['blocktime'],
                                       'addr' => $addr,
                                       'amount' => $amount,
                                       'amount_in' => $amount_in,
                                       'amount_out' => $amount_out,
                                       'txid' => $tx_btcd['txid'],
                                     );
            
        }
        
        return $tx_list_normal;
    }
    
}

// Bitpay Insight using multi address api.
// note: experimental.  not recommended for use.
class blockchain_api_insight_multiaddr  {
    
    public function get_addresses_transactions( $addr_list, $params ) {
        
        $addr_tx_limit = $params['addr-tx-limit'];
        $addrs = implode( ',', $addr_list );
        
        $url_mask = "%s/api/addrs/%s/txs?from=0&to=%s";
        $url = sprintf( $url_mask, $params['insight'], $addrs, $addr_tx_limit );
        
        mylogger()->log( "Retrieving transactions from $url", mylogger::info );
        
        // Todo:  make more robust with timeout, retries, etc.
        $buf = @file_get_contents( $url );
        
        // note: http_response_header is set by file_get_contents.
        // next line will throw exception wth code 1001 if response code not found.
        $server_http_code = httputil::http_response_header_http_code( @$http_response_header );
        
        if( $server_http_code == 404 ) {
            return array();
        }
        else if( $server_http_code != 200 ) {
            throw new Exception( "Got unexpected response code $server_http_code" );
        }

        mylogger()->log( "Received transactions from insight server.", mylogger::info );
        // file_put_contents( '/tmp/insight_multiaddr.json', $buf );

        
        $data = json_decode( $buf, true );
        $tx_list = @$data['items'];
        
        return $this->normalize_transactions( $tx_list, $addr_list );
    }
    
    protected function normalize_transactions( $tx_list_insight, $addr_list ) {
        
        // make a map for faster lookup in case of large address lists.
        $addrs = array();
        foreach( $addr_list as $addr ) {
            $addrs[$addr] = 1;
        }
        
        $last_used_addr = null;
        
//print_r( $tx_list_insight );        
        $tx_list_normal = array();
        foreach( $tx_list_insight as $tx_insight ) {
            $amount_in = 0;
            $amount_out = 0;
            $amount = 0;
            
            $idx = 0;
            foreach( $tx_insight['vin'] as $input ) {
                $idx ++;
                
                // $input['addr'] can be empty, eg for a coinbase transaction
                //    $input['coinbase'] will be set instead.
                if( !@$input['addr'] ) {
                    continue;
                }
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( @count( $input['addr'] ) > 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in input #%s, transaction %s.  skipping input.", @count( $input['addresses'] ), $idx, $tx_insight['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                
                if( @$addrs[$input['addr']] ) {
                    $amount_out = $amount_out + btcutil::btc_to_int( $input['value'] );
                    $last_used_addr = $input['addr'];
                }
            }
            $idx = 0;
            $not_understood = array( 'multisig' );
            foreach( $tx_insight['vout'] as $output ) {
                $idx ++;
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( @count( @$output['addresses'] ) > 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in output #%, transaction %s.  skipping output.", count( $output['addresses'] ), $idx, $tx_insight['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                // script_type can be hash160, p2sh or multisig.
                // If we include multisig in balance calcs, then our balances do not match with bitcoind.
                // So for now we include hash160 and p2sh only.
                // See also: https://github.com/coinbase/insight/issues/189
                if( @$addrs[$output['scriptPubKey']['addresses'][0]] && !in_array( $output['scriptPubKey']['type'], $not_understood ) ) {
                    $amount_in = $amount_in + btcutil::btc_to_int( $output['value'] );
                    $last_used_addr = $output['scriptPubKey']['addresses'][0];
                }
            }
            $amount = $amount_in - $amount_out;
                        
            $tx_list_normal[] = array( 'block_time' => $tx_insight['blocktime'],
                                       'addr' => $last_used_addr,
                                       'amount' => $amount,
                                       'amount_in' => $amount_in,
                                       'amount_out' => $amount_out,
                                       'txid' => $tx_insight['txid'],
                                     );
            
        }
        
        return $tx_list_normal;
    }
    
}


class blockchain_api_insight  {
    
    public function get_addresses_transactions( $addr_list, $params ) {
        $tx_list = array();
        foreach( $addr_list as $addr ) {
            $tx_list_new = $this->get_address_transactions( $addr, $params );
            $tx_list = array_merge( $tx_list, $tx_list_new );
        }
        return $tx_list;
    }
    
    protected function get_address_transactions( $addr, $params ) {
        
        // note:  insight /api/txs does not presently seem to support a limit.
        $addr_tx_limit = $params['addr-tx-limit'];
        
        $url_mask = "%s/api/txs?address=%s";
        $url = sprintf( $url_mask, $params['insight'], $addr );
        
        mylogger()->log( "Retrieving transactions from $url", mylogger::info );
        
        // Todo:  make more robust with timeout, retries, etc.
        $buf = @file_get_contents( $url );
        
        // note: http_response_header is set by file_get_contents.
        // next line will throw exception wth code 1001 if response code not found.
        $server_http_code = httputil::http_response_header_http_code( @$http_response_header );
        
        if( $server_http_code == 404 ) {
            return array();
        }
        else if( $server_http_code != 200 ) {
            throw new Exception( "Got unexpected response code $server_http_code" );
        }
        
        mylogger()->log( "Received transactions from insight server.", mylogger::info );
        // file_put_contents( '/tmp/insight.json', $buf );        
        
        $data = json_decode( $buf, true );
        $tx_list = @$data['txs'];
//print_r( $tx_list ); exit;
        
        return $this->normalize_transactions( $tx_list, $addr );
    }
    
    protected function normalize_transactions( $tx_list_insight, $addr ) {
        
//print_r( $tx_list_insight );        
        $tx_list_normal = array();
        foreach( $tx_list_insight as $tx_insight ) {
            $amount_in = 0;
            $amount_out = 0;
            $amount = 0;
            
            $idx = 0;
            foreach( $tx_insight['vin'] as $input ) {
                $idx ++;
                
                // $input['addr'] can be empty, eg for a coinbase transaction
                //    $input['coinbase'] will be set instead.
                if( !@$input['addr'] ) {
                    continue;
                }
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( @count( $input['addr'] ) > 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in input #%s, transaction %s.  skipping input.", @count( $input['addresses'] ), $idx, $tx_insight['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                
                if( @$input['addr'] == $addr ) {
                    $amount_out = $amount_out + btcutil::btc_to_int( $input['value'] );
                }
            }
            $idx = 0;
            $not_understood = array( 'multisig' );
            foreach( $tx_insight['vout'] as $output ) {
                $idx ++;
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( @count( @$output['addresses'] ) > 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in output #%, transaction %s.  skipping output.", count( $output['addresses'] ), $idx, $tx_insight['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                // script_type can be hash160, p2sh or multisig.
                // If we include multisig in balance calcs, then our balances do not match with bitcoind.
                // So for now we include hash160 and p2sh only.
                // See also: https://github.com/coinbase/insight/issues/189
                if( @$output['scriptPubKey']['addresses'][0] == $addr && !in_array( $output['scriptPubKey']['type'], $not_understood ) ) {
                    $amount_in = $amount_in + btcutil::btc_to_int( $output['value'] );
                }
            }
            $amount = $amount_in - $amount_out;
                        
            $tx_list_normal[] = array( 'block_time' => $tx_insight['blocktime'],
                                       'addr' => $addr,
                                       'amount' => $amount,
                                       'amount_in' => $amount_in,
                                       'amount_out' => $amount_out,
                                       'txid' => $tx_insight['txid'],
                                     );
            
        }
        
        return $tx_list_normal;
    }
    
}

