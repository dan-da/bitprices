#!/usr/bin/env php
<?php

require_once dirname(__FILE__) . '/lib/strict_mode.funcs.php';
require_once dirname(__FILE__) . '/lib/mylogger.class.php';
require_once dirname(__FILE__) . '/lib/mysqlutil.class.php';
require_once dirname(__FILE__) . '/lib/httputil.class.php';
require_once dirname(__FILE__) . '/lib/html_table.class.php';
require_once dirname(__FILE__) . '/lib/bitcoin-php/bitcoin.inc';

require_once dirname(__FILE__) . '/lib/validator/AddressValidator.php';
use \LinusU\Bitcoin\AddressValidator;

require_once dirname(__FILE__) . '/lib/blockchain_api.php';


define( 'SATOSHI', 100000000 );


/**
 * Our main function.
 */
function main( $argv ) {
    ini_set('memory_limit', -1 );

    $worker = new bitprices();
    try {
        return $worker->run( $argv );
    }
    catch( Exception $e ) {
        mylogger()->log_exception( $e );
        
        // print validation errors to stderr.
        if( $e->getCode() == 2 ) {
            fprintf( STDERR, $e->getMessage() . "\n\n" );
        }
        return $e->getCode() ?: 1;
    }
}

exit( main( $argv ) );


class bitprices {
    
    public function run( $argv ) {
        $params = $this->get_params();
        if( !$params ) {
            return 1;
        }
        
        date_default_timezone_set("UTC");
        
        $start = microtime( true );
        
        $addrs = $this->get_addresses();
        $results = $this->process_addresses( $addrs );
        $this->print_results( $results );
        
        $end = microtime(true);
        $duration = $end - $start;
        echo "\nExecution time: $duration seconds\n\n";
        
    }
    
    protected function get_params() {
        $params = getopt( 'g', array( 'date_start:', 'date_end:',
                                      'addresses:', 'addressfile:',
                                      'direction:', 'currency:',
                                      'cols:', 'outfile:',
                                      'format:', 'logfile:',
                                      'toshi:', 'toshi-fast',
                                      'addr-tx-limit:', 'testnet',
                                      'btcd-rpc-host:', 'btcd-rpc-port:',
                                      'btcd-rpc-user:', 'btcd-rpc-pass:',
                                      'api:', 'insight:',
                                      ) );
        
        if( !isset($params['g']) ) {
            $this->print_help();
            return false;
        }
        if( !@$params['addresses'] && !@$params['addressfile'] ) {
            $this->print_help();
            return false;
        }

        if( !@$params['api'] ) {
            $params['api'] == 'toshi';
        }

        if( !@$params['insight'] ) {
            $params['insight'] = 'https://insight.bitpay.com';
        }
        
        $params['toshi-fast'] = isset($params['toshi-fast']);
        $params['testnet'] = isset($params['testnet']);
        
        if( !@$params['toshi'] ) {
            $params['toshi'] = 'https://bitcoin.toshi.io';
        }

        if( !@$params['btcd-rpc-host'] ) {
            $params['btcd-rpc-port'] = '127.0.0.1';  // use localhost
        }
        
        if( !@$params['btcd-rpc-port'] ) {
            $params['btcd-rpc-port'] = 8334;  // use default port.
        }
        

        if( !@$params['addr-tx-limit'] ) {
            $params['addr-tx-limit'] = 1000;
        }
        
        if( @$params['logfile'] ) {
            mylogger()->set_log_file( $params['logfile'] );
            mylogger()->echo_log = false;
        }
        
        $params['direction'] = @$params['direction'] ?: 'both';
        if( !in_array( @$params['direction'], array( 'in', 'out', 'both' ) ) ) {
            $this->print_help();
            return false;
        }
        
        $params['date_start'] = @$params['date_start'] ? strtotime($params['date_start']) : 0;
        $params['date_end'] = @$params['date_end'] ? strtotime($params['date_end']) : time();
        
        $params['currency'] = strtoupper( @$params['currency'] ) ?: 'USD';
        $params['cols'] = $this->get_cols( @$params['cols'] );
        
        $params['format'] = @$params['format'] ?: 'txt';

        return $params;
    }
    
    protected function get_addresses() {
        $params = $this->get_params();
        
        $list = array();
        if( @$params['addresses'] ) {
            $list = explode( ',', $this->strip_whitespace( $params['addresses'] ) );
        }
        if( @$params['addressfile'] ) {
            $csv = implode( ',', file( @$params['addressfile'] ) );
            $list = explode( ',', $this->strip_whitespace( $csv ) );
        }
        foreach( $list as $idx => $addr ) {
            if( !$addr ) {
                unset( $list[$idx] );
                continue;
            }
            $version = $params['testnet'] ? AddressValidator::TESTNET : AddressValidator::MAINNET;
            if( !AddressValidator::isValid( $addr, $version ) ) {
                // code 2 means an input validation exception.
                throw new Exception( "Bitcoin address $addr is invalid", 2 );
            }
        }
        if( !count( $list ) ) {
            throw new Exception( "No valid addresses to process." );
        }
        return $list;
    }
    
    protected function get_cols( $arg ) {
        $arg = $this->strip_whitespace( $arg );
        if( !$arg ) {
            $arg = 'date,time,addrshort,btcin,btcout,btcbalance,fiatin,fiatout,fiatbalance,fiatprice';
        }
        return explode( ',', $arg );
    }
    
    protected function strip_whitespace( $str ) {
        return preg_replace('/\s+/', '', $str);
    }
    
    public function print_help() {
        
 
        $buf = <<< END

   bitprices.php

   This script generates a report of transactions with the USD value
   at the time of each transaction.

   Options:

    -g                   go!
    
    --addresses=<csv>    comma separated list of bitcoin addresses
    --addressfile=<path> file containing bitcoin addresses, one per line.
    
    --api=<api>          toshi|btcd|insight.   default = toshi.
    
    --direction=<dir>    transactions in | out | both   default = both.
    
    --date_start=<date>  Look for transactions since date. default = all.
    --date_end=<date>    Look for transactions until date. default = now.
    
    --currency=<curr>    symbol supported by bitcoinaverage.com.  default = USD.
    
    --cols=<cols>        default=date,time,addrshort,btcin,btcout,btcbalance
                                 fiatin,fiatout,fiatbalance,fiatprice
                         others=address,tx,txshort
                                btcbalanceperiod,fiatbalanceperiod
                                
    --outfile=<path>     specify output file path.
    --format=<format>    txt|csv|json|jsonpretty|html|all     default=txt
    
                         if all is specified then a file will be created
                         for each format with appropriate extension.
                         only works when outfile is specified.
                         
    --toshi=<url>       toshi server. defaults to https://bitcoin.toshi.io
    --toshi-fast        if set, toshi server supports filtered transactions.
    
    --btcd-rpc-host=<h> btcd rpc host.  default = 127.0.0.1
    --btcd-rpc-port=<p> btcd rpc port.  default = 8334
    --btcd-rpc-user=<u> btcd rpc username.
    --btcd-rpc-pass=<p> btcd rpc password.
    
    --insight=<url>     insight server. defaults to https://insight.bitpay.com
    
    --addr-tx-limit=<n> per address transaction limit. default = 1000
    --testnet           use testnet. only affects addr validation.


END;

   fprintf( STDERR, $buf );       
        
    }
    
    protected function process_addresses( $addrs ) {
        
        $trans = $this->get_matching_transactions( $addrs );
        
        $params = $this->get_params();
        $currency = $params['currency'];
        
        $results = array();
        foreach( $trans as $tx ) {
            $tx['exchange_rate'] = $this->get_historic_price( $currency, $tx['block_time'] );
            $tx['fiat_amount_in'] = btcutil::btc_display( $tx['amount_in'] * $tx['exchange_rate'] );
            $tx['fiat_amount_out'] = btcutil::btc_display( $tx['amount_out'] * $tx['exchange_rate'] );
            $tx['fiat_currency'] = $currency;
            $results[] = $tx;
        }
        
        return $results;
    }
    
    /**
     * queries a blockchain api provider to obtain historical transactions for
     * list of input addresses.
     */
    protected function get_matching_transactions( $addrs ) {
        $params = $this->get_params();
        
        $api = blockchain_api_factory::instance( $params['api'] );
        $tx_list = $api->get_addresses_transactions( $addrs, $params );
//        print_r( $tx_list ); exit;
        
        return $tx_list;
    }

    protected function get_historic_price( $currency, $timestamp ) {
        
        $date = date( 'Y-m-d', $timestamp );
        
        // if date is today, then get 24 hour average.
        if( $date == date('Y-m-d', time() )) {
            return $this->get_24_hour_avg_price( $currency );
        }
        
        $map = self::get_historic_prices( $currency );
        $price = @$map[$date];
        
        // if price is not available in cached file, then force a download.
        if( !$price ) {
            $map = self::get_historic_prices( $currency, $download = true );
            $date = date( 'Y-m-d', $timestamp );
            $price = @$map[$date];
        }
        
        return $price;
    }
    
    protected function get_24_hour_avg_price( $currency ) {
        $url_mask = 'https://api.bitcoinaverage.com/ticker/global/%s/';
        $url = sprintf( $url_mask, strtoupper( $currency ) );
        
        mylogger()->log( "Retrieving $currency 24 hour average price from bitcoinaverage.com", mylogger::info );
        $buf = file_get_contents( $url );
        $data = json_decode( $buf, true );
        return $data['24h_avg'] * 100;
    }
    
    protected static function get_historic_prices($currency, $download = false) {
        
        static $maps = array();
        static $downloaded_map = array();
        
        $map = @$maps[$currency];
        if( $map && !$download ) {
            return $map;
        }

        // if we already downloaded this run, then abort.
        $downloaded = @$downloaded_map[$currency];
        if( $downloaded ) {
            return null;
        }
        
        $fname = dirname(__FILE__) . sprintf( '/price_history/per_day_all_time_history.%s.csv', $currency );
        
        if( !file_exists( $fname ) || $download ) {
            $dir = dirname( $fname );
            $dir || mkdir( $dir );
            
            $url_mask = 'https://api.bitcoinaverage.com/history/%s/per_day_all_time_history.csv';
            $url = sprintf( $url_mask, strtoupper( $currency ) );
            mylogger()->log( "Retrieving $currency price history from bitcoinaverage.com", mylogger::info );
            $buf = file_get_contents( $url );
            file_put_contents( $fname, $buf );
            
            $downloaded_map[$currency] = true;
        }
        
        $fh = fopen( $fname, 'r' );
        
        $map = array();
        while( $row = fgetcsv( $fh ) ) {
            list( $date, $high, $low, $avg, $volume ) = $row;
            $date = date('Y-m-d', strtotime( $date ) );
            $map[$date] = $avg * 100;
        }
        $maps[$currency] = $map;
  
        return $map;
    }
    
    protected function shorten_addr( $address ) {
        return substr( $address, 0, 3 ) . '..' . substr( $address, -3 );
    }
    
    protected function format_results( $results, $format ) {
        
        $params = $this->get_params();
        $direction = $params['direction'];
        
        // This is an ugly hack so that html format will always include
        // addressweb and txweb columns at the end.  For use in linking to block explorers.
        $cols = $params['cols'];
        if( $format == 'html' ) {
            $cols[] = 'addressweb';
            $cols[] = 'txweb';
        }
        
        $cb = function($a, $b) { return $a['block_time'] == $b['block_time'] ? 0 : $a['block_time'] > $b['block_time'] ? 1 : -1; };
        usort( $results, $cb );
        
        $btc_balance = 0;  $btc_balance_period = 0;
        $fiat_balance = 0; $fiat_balance_period = 0;
                
        $nr = array();
        foreach( $results as $r ) {
            
            $btc_balance += $r['amount_in'] - $r['amount_out'];
            $fiat_balance += $r['fiat_amount_in'] - $r['fiat_amount_out'];

            // filter out transactions by direction and date params.
            if( $direction == 'in' && !$r['amount_in'] ) {
                continue;
            }
            else if( $direction == 'out' && !$r['amount_out'] ) {
                continue;
            }
            else if( $r['block_time'] < $params['date_start'] ) {
                continue;
            }
            else if( $r['block_time'] > $params['date_end'] +3600*24-1 ) {
                continue;
            }            

            $btc_balance_period += $r['amount_in'] - $r['amount_out'];
            $fiat_balance_period += $r['fiat_amount_in'] - $r['fiat_amount_out'];
            
            $fc = strtoupper( $r['fiat_currency'] );
                       
            $row = array();
            
            foreach( $cols as $col ) {
                switch( $col ) {
                    case 'date': $row[ucfirst($col)] = date('Y-m-d', $r['block_time'] ); break;
                    case 'time': $row[ucfirst($col)] = date('H:i:s', $r['block_time'] ); break;
                    case 'addrshort': $row['Addr Short'] = $this->shorten_addr( $r['addr'] ); break;
                    case 'address': $row['Address'] = $r['addr']; break;
                    case 'addressweb': $row['AddressWeb'] = $r['addr']; break;
                    case 'btcin': $row['BTC In'] = btcutil::btc_display( $r['amount_in'] ); break;
                    case 'btcout': $row['BTC Out'] = btcutil::btc_display( $r['amount_out'] ); break;
                    case 'btcbalance': $row['BTC Balance'] = btcutil::btc_display( $btc_balance ); break;
                    case 'btcbalanceperiod': $row['BTC Balance Period'] = btcutil::btc_display( $btc_balance_period ); break;
                    case 'fiatin': $row[$fc . ' In'] = btcutil::fiat_display( $r['fiat_amount_in'] ); break;
                    case 'fiatout': $row[$fc . ' Out'] = btcutil::fiat_display( $r['fiat_amount_out'] ); break;
                    case 'fiatbalance': $row[$fc . ' Balance'] = btcutil::fiat_display( $fiat_balance ); break;
                    case 'fiatbalanceperiod': $row[$fc . ' Balance Period'] = btcutil::fiat_display( $fiat_balance_period ); break;
                    case 'fiatprice': $row[$fc . ' Price'] = btcutil::fiat_display( $r['exchange_rate'] ); break;
                    case 'txshort': $row['Tx Short'] = $this->shorten_addr( $r['txid'] ); break;
                    case 'tx': $row['Tx'] = $r['txid']; break;
                    case 'txweb': $row['TxWeb'] = $r['txid']; break;
                }
            }
            $nr[] = $row;
            
        }
        
        return $nr;
    }
    
    protected function print_results( $results ) {
        $params = $this->get_params();
        $outfile = @$params['outfile'];
        $format = @$params['format'];
        
        if( $outfile && $format == 'all' ) {
            $formats = array( 'txt', 'csv', 'json', 'jsonpretty', 'html' );
            
            foreach( $formats as $format ) {
                
                $outfile = sprintf( '%s/%s.%s',
                                    pathinfo($outfile, PATHINFO_DIRNAME),
                                    pathinfo($outfile, PATHINFO_FILENAME),
                                    $format );
                
                $this->print_results_worker( $results, $outfile, $format );
            }
        }
        else {
            $this->print_results_worker( $results, $outfile, $format );
        }
    }
    
    protected function print_results_worker( $results, $outfile, $format ) {

        $formatted = $this->format_results( $results, $format );
        
        $fname = $outfile ?: 'php://stdout';
        $fh = fopen( $fname, 'w' );

        switch( $format ) {
            case 'txt':  self::write_results_fixed_width( $fh, $formatted ); break;
            case 'csv':  self::write_results_csv( $fh, $formatted ); break;
            case 'json':  self::write_results_json( $fh, $formatted ); break;
            case 'html':  self::write_results_html( $fh, $formatted ); break;
            case 'jsonpretty':  self::write_results_jsonpretty( $fh, $formatted ); break;
        }

        fclose( $fh );

        echo "\n\nReport was written to $fname\n\n";
    }

    static public function write_results_json( $fh, $results ) {
        fwrite( $fh, json_encode( $results ) );
    }

    static public function write_results_jsonpretty( $fh, $results ) {
        fwrite( $fh, json_encode( $results,  JSON_PRETTY_PRINT ) );
    }
    
    static public function write_results_csv( $fh, $results ) {
        if( @$results[0] ) {
            fputcsv( $fh, array_keys( $results[0] ) );
        }
        
        foreach( $results as $row ) {
            fputcsv( $fh, $row );
        }
    }

    static public function write_results_html( $fh, $results ) {
        
        foreach( $results as &$row ) {
            
            $addr_url = sprintf( 'http://blockchain.info/address/%s', $row['AddressWeb'] );
            $tx_url = sprintf( 'http://blockchain.info/tx/%s', $row['TxWeb'] );
    
            if( isset( $row['Date'] ) ) {
                $row['Date'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Date'] );
            }
            if( isset( $row['Addr Short'] ) ) {
                $row['Addr Short'] = sprintf( '<a href="%s">%s</a>', $addr_url, $row['Addr Short'] );
            }
            if( isset( $row['Address'] ) ) {
                $row['Address'] = sprintf( '<a href="%s">%s</a>', $addr_url, $row['Address'] );
            }
            if( isset( $row['Tx Short'] ) ) {
                $row['Tx Short'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Tx Short'] );
            }
            if( isset( $row['Tx'] ) ) {
                $row['Tx'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Tx'] );
            }
            
            unset( $row['AddressWeb'] );
            unset( $row['TxWeb'] );
        }

        if( @$results[0] ) {
            $header = array_keys( $results[0] );
        }
        
        $table = new html_table();
        $table->header_attrs = array();
        $table->table_attrs = array( 'class' => 'bitprices bordered' );
        $html = $table->table_with_header( $results, $header );
        
        fwrite( $fh, $html );
    }
    
    static public function write_results_fixed_width( $fh, $results ) {
        
        if( !count( $results ) ) {
            $str = <<< 'END'
+------------+
| No results |
+------------+

END;
            fwrite( $fh, $str );
            return;
        }

        $obj_arr = function ( $t ) {
           return is_object( $t ) ? get_object_vars( $t ) : $t;
        };
        
        $header = array_keys( $obj_arr( $results[0] ) );
        $col_widths = array();

        $calc_row_col_widths = function( &$col_widths, $row ) {
            $idx = 0;
            foreach( $row as $val ) {
                $len = strlen( $val );
                if( $len > @$col_widths[$idx] ) {
                    $col_widths[$idx] = $len;
                }
                $idx ++;
            }
        };
        
        $calc_row_col_widths( $col_widths, $header );
        foreach( $results as $row ) {
            $row = $obj_arr( $row );
            $calc_row_col_widths( $col_widths, $row );
        }

        $print_row = function( $col_widths, $row ) {
            $buf = '|';
            $idx = 0;
            foreach( $row as $val ) {
                $pad_type = is_numeric( $val ) ? STR_PAD_LEFT : STR_PAD_RIGHT;
                $buf .= ' ' . str_pad( $val, $col_widths[$idx], ' ', $pad_type ) . " |";
                $idx ++;
            }
            return $buf . "\n";
        };
        
        $print_divider_row = function( $col_widths ) {
            $buf = '+';
            foreach( $col_widths as $width ) {
                $buf .= '-' . str_pad( '-', $width, '-' ) . "-+";
            }
            $buf .= "\n";
            return $buf;
        };
        
        $buf = $print_divider_row( $col_widths );
        $buf .= $print_row( $col_widths, $header );
        $buf .= $print_divider_row( $col_widths );
        fwrite( $fh, $buf );
        foreach( $results as $row ) {
            $row = $obj_arr( $row );
            $buf = $print_row( $col_widths, $row );
            fwrite( $fh, $buf );
        }
        $buf = $print_divider_row( $col_widths );
        fwrite( $fh, $buf );
    }
    
}

class btcutil {
    static public function btc_to_int( $val ) {
        return $val * 100000000;
    }

    static public function int_to_btc( $val ) {
        return $val * 100000000;
    }
    
    static public function btc_display( $val ) {
        return number_format( round( $val / SATOSHI, 8 ), 8, '.', '');
    }

    static public function fiat_display( $val ) {
        return number_format( round( $val / 100, 2 ), 2, '.', '');
    }    
}




