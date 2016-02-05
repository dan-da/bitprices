<?php

interface blockchain_api {
    public function get_addresses_transactions( $addr_list, $start_time, $end_time, $params );
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


/**
 * An implementation of blockchain_api that uses the toshi oracle.
 *
 * Supports using any toshi host. Toshi is an open-source project.
 *
 * For info about Toshi, see:
 *  + https://toshi.io/
 *  + https://github.com/coinbase/toshi
 */
class blockchain_api_toshi implements blockchain_api {
    
    public function get_addresses_transactions( $addr_list, $start_time, $end_time, $params ) {
        $tx_list = array();
        foreach( $addr_list as $addr ) {
            $tx_list_new = $this->get_address_transactions( $addr, $start_time, $end_time, $params );
            $tx_list = array_merge( $tx_list, $tx_list_new );
        }
        return $tx_list;
    }
    
    private function get_address_transactions( $addr, $start_time, $end_time, $params ) {
        
        $tx_method = $params['toshi-fast'] ? 'transactionsfiltered' : 'transactions';
        $addr_tx_limit = $params['addr-tx-limit'];
        
        $url_mask = "%s/api/v0/addresses/%s/%s?limit=%s";
        $url = sprintf( $url_mask, $params['toshi'], $addr, $tx_method, $addr_tx_limit );
        
        mylogger()->log( "Retrieving transactions from $url", mylogger::info );
        
        // Todo:  make more robust with timeout, retries, etc.
        $buf = @file_get_contents( $url );

        $oracle_raw = $params['oracle-raw'];
        if( $oracle_raw ) {
            file_put_contents( $oracle_raw, $buf );
        }
        
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
        
        $oracle_json = $params['oracle-json'];
        if( $oracle_json ) {
            file_put_contents( $oracle_json, json_encode( $data,  JSON_PRETTY_PRINT ) );
        }
        
        return $this->normalize_transactions( $tx_list, $addr, $start_time, $end_time );
    }
    
    protected function normalize_transactions( $tx_list_toshi, $addr, $start_time, $end_time ) {

        $tx_list_toshi = array_reverse( $tx_list_toshi );
        $tx_list_normal = array();
        foreach( $tx_list_toshi as $tx_toshi ) {
            
            $in_period = $tx_toshi['block_time'] >= $start_time && $tx_toshi['block_time'] <= $end_time;
            if( !$in_period ) {
                continue;
            }
            
            $amount_in = 0;
            $amount_out = 0;
            $amount = 0;
            
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
                    $amount_in = $output['amount'];
                    
                    $tx_list_normal[] = array( 'block_time' => strtotime($tx_toshi['block_time']),
                                               'addr' => $addr,
                                               'amount' => $amount_in,
                                               'amount_in' => $amount_in,
                                               'amount_out' => 0,
                                               'txid' => $tx_toshi['hash'],
                                             );
                }
            }
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
                    $amount_out = $input['amount'];
                    
                    $tx_list_normal[] = array( 'block_time' => strtotime($tx_toshi['block_time']),
                                               'addr' => $addr,
                                               'amount' => 0 -$amount_out,
                                               'amount_in' => 0,
                                               'amount_out' => $amount_out,
                                               'txid' => $tx_toshi['hash'],
                                             );
                }
            }
        }
        return $tx_list_normal;
    }
    
}


/**
 * An implementation of blockchain_api that uses the btcd oracle.
 *
 * Supports using any btcd host. btcd is an open-source project.
 *
 * Note: this class requires btcd pull request 516, added on Nov 16, 2015.
 * https://github.com/btcsuite/btcd/pull/516
 *
 * For info about btcd, see:
 *  + https://github.com/btcsuite/btcd
 */
class blockchain_api_btcd implements blockchain_api {
    
    public function get_addresses_transactions( $addr_list, $start_time, $end_time, $params ) {
        $tx_list = array();
        foreach( $addr_list as $addr ) {
            $tx_list_new = $this->get_address_transactions( $addr, $start_time, $end_time, $params );
            $tx_list = array_merge( $tx_list, $tx_list_new );
        }
        return $tx_list;
    }
    
    private function get_address_transactions( $addr, $start_time, $end_time, $params ) {

        $btcd = sprintf( '%s:%s', $params['btcd-rpc-host'], $params['btcd-rpc-port'] );
        mylogger()->log( "Retrieving transactions from btcd $btcd", mylogger::info );
        
        $url = sprintf( 'http://%s:%s@%s:%s/', $params['btcd-rpc-user'], $params['btcd-rpc-pass'],
                                          $params['btcd-rpc-host'], $params['btcd-rpc-port']);
        $rpc = new BitcoinClient( $url, false, 'BTC' );
        
        $tx_limit = (int)$params['addr-tx-limit'];

        try {
            $tx_list = $rpc->searchrawtransactions( $addr, $verbose=1, $skip=0, $count=$tx_limit, $vinExtra=1, $reverse=false, $filterAddr=array( $addr ) );
            mylogger()->log( "Received transactions from btcd.", mylogger::info );
        }
        catch( Exception $e ) {
            // code -5 : No information available about transaction
            if( $e->getCode() != -5 ) {
                mylogger()->log_exception($e);
                mylogger()->log( "Handled exception while calling btcd::searchrawtransactions.  continuing", mylogger::warning );
            }
            $tx_list = [];
        }
        
        $oracle_raw = $params['oracle-raw'];
        if( $oracle_raw ) {
            file_put_contents( $oracle_raw, $rpc->last_response() );
        }

        $oracle_json = $params['oracle-json'];
        if( $oracle_json ) {
            file_put_contents( $oracle_json, json_encode( $tx_list,  JSON_PRETTY_PRINT ) );
        }
        
        return $this->normalize_transactions( $tx_list, $addr, $start_time, $end_time );
    }
    
    protected function normalize_transactions( $tx_list_btcd, $addr, $start_time, $end_time ) {

        $tx_list_normal = array();
        foreach( $tx_list_btcd as $tx_btcd ) {
            $in_period = $tx_btcd['blocktime'] >= $start_time && $tx_btcd['blocktime'] <= $end_time;
            if( !$in_period ) {
                continue;
            }           
            
            $idx = 0;
            $not_understood = array( 'multisig' );
            foreach( $tx_btcd['vout'] as $output ) {
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
                    $amount_in = btcutil::btc_to_int( $output['value'] );
                    
                    $tx_list_normal[] = array( 'block_time' => $tx_btcd['blocktime'],
                                               'addr' => $addr,
                                               'amount' => $amount_in,
                                               'amount_in' => $amount_in,
                                               'amount_out' => 0,
                                               'txid' => $tx_btcd['txid'],
                                             );
                }
            }
            
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
                    $amount_out = btcutil::btc_to_int( $prevOut['value'] );
                    
                    $tx_list_normal[] = array( 'block_time' => $tx_btcd['blocktime'],
                                               'addr' => $addr,
                                               'amount' => 0 - $amount_out,
                                               'amount_in' => 0,
                                               'amount_out' => $amount_out,
                                               'txid' => $tx_btcd['txid'],
                                             );
                }
            }
        }
        
        return $tx_list_normal;
    }
    
}

/**
 * An implementation of blockchain_api that uses the insight oracle
 * with multi-address support.
 *
 * note: experimental.  problems may occur with multiple addresses.
 *       not recommended for use.
 *
 * Supports using any insight host. insight is an open-source project.
 *
 * For info about insight, see:
 *  + https://github.com/bitpay/insight
 */
class blockchain_api_insight_multiaddr  {
    
    public function get_addresses_transactions( $addr_list, $start_time, $end_time, $params ) {
        
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
        
        $oracle_raw = $params['oracle-raw'];
        if( $oracle_raw ) {
            file_put_contents( $oracle_raw, $buf );
        }        
        
        $data = json_decode( $buf, true );
        $tx_list = @$data['items'];

        $oracle_json = $params['oracle-json'];
        if( $oracle_json ) {
            file_put_contents( $oracle_json, json_encode( $data,  JSON_PRETTY_PRINT ) );
        }
        
        return $this->normalize_transactions( $tx_list, $addr_list, $start_time, $end_time );
    }
    
    protected function normalize_transactions( $tx_list_insight, $addr_list, $start_time, $end_time ) {
        array_reverse( $tx_list_insight );
        
        // make a map for faster lookup in case of large address lists.
        $addrs = array();
        foreach( $addr_list as $addr ) {
            $addrs[$addr] = 1;
        }
        
        $last_used_addr = null;
        
        $tx_list_normal = array();
        foreach( $tx_list_insight as $tx_insight ) {
            
            $in_period = $tx_insight['blocktime'] >= $start_time && $tx_insight['blocktime'] <= $end_time;
            if( !$in_period ) {
                continue;
            }
            
            $amount_in = 0;
            $amount_out = 0;
            $amount = 0;
            
            $idx = 0;
            $not_understood = array( 'multisig' );
            foreach( $tx_insight['vout'] as $output ) {
                $idx ++;
                
                // more than one address typically means multisig.  we ignore it.
                // Example address with multi-sig 1AJbsFZ64EpEfS5UAjAfcUG8pH8Jn3rn1F
                //     tx = 2daea775df11a98646c475c04247b998bbed053dc0c72db162dd6b0a99a59c26
                if( @count( @$output['scriptPubKey']['addresses'] ) > 1 ) {
                    $msg = sprintf( "Unsupported number of addresses (%s) in output #%, transaction %s.  skipping output.", count( $output['addresses'] ), $idx, $tx_insight['hash'] );
                    // mylogger()->log( $msg, mylogger::warning );
                    continue;
                }
                // script_type can be hash160, p2sh or multisig.
                // If we include multisig in balance calcs, then our balances do not match with bitcoind.
                // So for now we include hash160 and p2sh only.
                // See also: https://github.com/coinbase/insight/issues/189
                if( @$addrs[$output['scriptPubKey']['addresses'][0]] && !in_array( $output['scriptPubKey']['type'], $not_understood ) ) {
                    $amount_in = btcutil::btc_to_int( $output['value'] );
                    $last_used_addr = $output['scriptPubKey']['addresses'][0];
                    
                    $tx_list_normal[] = array( 'block_time' => $tx_insight['blocktime'],
                                               'addr' => $addr,
                                               'amount' => $amount_in,
                                               'amount_in' => $amount_in,
                                               'amount_out' => 0,
                                               'txid' => $tx_insight['txid'],
                                             );
                }
            }
            
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
                    $amount_out = btcutil::btc_to_int( $input['value'] );
                    $last_used_addr = $input['addr'];
                    
                    $tx_list_normal[] = array( 'block_time' => $tx_insight['blocktime'],
                                               'addr' => $addr,
                                               'amount' => 0 -$amount_out,
                                               'amount_in' => 0,
                                               'amount_out' => $amount_out,
                                               'txid' => $tx_insight['txid'],
                                             );
                }
            }
        }
        
        return $tx_list_normal;
    }
    
}

/**
 * An implementation of blockchain_api that uses the insight oracle
 * with single-address support.
 *
 * Supports using any insight host. insight is an open-source project.
 *
 * For info about insight, see:
 *  + https://github.com/bitpay/insight
 */
class blockchain_api_insight  {
    
    public function get_addresses_transactions( $addr_list, $start_time, $end_time, $params ) {
        $tx_list = array();
        foreach( $addr_list as $addr ) {
            $tx_list_new = $this->get_address_transactions( $addr, $start_time, $end_time, $params );
            $tx_list = array_merge( $tx_list, $tx_list_new );
        }
        return $tx_list;
    }
    
    protected function get_address_transactions( $addr, $start_time, $end_time, $params ) {
        
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
        
        $oracle_raw = $params['oracle-raw'];
        if( $oracle_raw ) {
            file_put_contents( $oracle_raw, $buf );
        }        
        
        $data = json_decode( $buf, true );
        $tx_list = @$data['txs'];
        
        $oracle_json = $params['oracle-json'];
        if( $oracle_json ) {
            file_put_contents( $oracle_json, json_encode( $data,  JSON_PRETTY_PRINT ) );
        }
        
        return $this->normalize_transactions( $tx_list, $addr, $start_time, $end_time );
    }
    
    protected function normalize_transactions( $tx_list_insight, $addr ) {

        // attempt to get insight tx ordered in same way as btcd, toshi.
        // doesn't work 100% though.   :(
        $tx_list_insight = array_reverse( $tx_list_insight );
        $date_map = array();
        foreach( $tx_list_insight as $tx ) {

            $time = $tx['blocktime'];
            
            $in_period = $time >= $start_time && $time <= $end_time;
            if( !$in_period ) {
                continue;
            }
            
            if( isset( $date_map[$time] ) ) {
                $date_map[$time][] = $tx;
            }
            else {
                $date_map[$time] = array( $tx );
            }
        }
        $tx_list_insight = array();
        foreach( $date_map as $date => $txlist ) {
            $txlist = array_reverse( $txlist );
            $tx_list_insight = array_merge( $tx_list_insight, $txlist );
        }

        $tx_list_normal = array();
        foreach( $tx_list_insight as $tx_insight ) {            
            $amount_in = (int)0;
            $amount_out = (int)0;
            $amount = 0;

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
                    $amount_in = btcutil::btc_to_int( $output['value'] );
                    
                    $tx_list_normal[] = array( 'block_time' => $tx_insight['blocktime'],
                                               'addr' => $addr,
                                               'amount' => $amount_in,
                                               'amount_in' => $amount_in,
                                               'amount_out' => 0,
                                               'txid' => $tx_insight['txid'],
                                             );
                }
            }
            
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
                    $amount_out = btcutil::btc_to_int( $input['value'] );
                    
                    $tx_list_normal[] = array( 'block_time' => $tx_insight['blocktime'],
                                               'addr' => $addr,
                                               'amount' => 0 -$amount_out,
                                               'amount_in' => 0,
                                               'amount_out' => $amount_out,
                                               'txid' => $tx_insight['txid'],
                                             );
                }
            }
        }
        return $tx_list_normal;
    }
    
}

