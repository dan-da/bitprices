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

// no global scope execution past this point!
exit( main( $argv ) );


/**
 * Our main function.  It only performs top-level exception handling.
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

/**
 * Main App
 */
class bitprices {

    // where all the work starts and ends.
    public function run( $argv ) {
        $params = $this->get_cli_params();
        
        $rc = $this->process_cli_params( $params );
        if( $rc != 0 ) {
            return $rc;
        }
        $params = $this->get_params();
        $format = $params['format'];
        $report_type = $params['report-type'];
        
        date_default_timezone_set("UTC");
        
        $start = microtime( true );
        
        $tx_list = $this->gettxfromuser();
        if( !$tx_list ) {
            $addrs = $this->get_addresses();
            $tx_list = $this->get_matching_transactions( $addrs );
        }
        $results = $this->process_transactions( $tx_list );
        
        $meta = null;
        
        switch( $report_type ) {
            case 'schedule_d': $rows = $this->gen_report_schedule_d( $results, $format );  break;
            case 'matrix': $rows = $this->gen_report_matrix( $results, $format );  break;
            default: list($rows, $meta) = $this->gen_report_tx( $results, $format ); break;
        }

        $this->print_results( $rows, $meta, $format );
        
        $end = microtime(true);
        $duration = $end - $start;
        echo "\nExecution time: $duration seconds\n\n";
        
    }    

    /**
     * returns the CLI params, exactly as entered by user.
     */
    protected function get_cli_params() {
        $params = getopt( 'g', array( 'date-start:', 'date-end:',
                                      'addresses:', 'addressfile:', 'txfile:',
                                      'direction:', 'currency:',
                                      'cols:', 'outfile:',
                                      'format:', 'logfile:',
                                      'toshi:', 'toshi-fast',
                                      'addr-tx-limit:', 'testnet',
                                      'btcd-rpc-host:', 'btcd-rpc-port:',
                                      'btcd-rpc-user:', 'btcd-rpc-pass:',
                                      'api:', 'insight:',
                                      'list-templates', 'list-cols',
                                      'report-type:', 'cost-method:',
                                      'include-transfer',
                                      'disable-transfer',
                                      'oracle-raw:', 'oracle-json:',
                                      'version',
                                      ) );        

        return $params;
    }

    /**
     * processes and normalizes the CLI params. adds defaults
     * and ensure each value is set.
     */
    protected function process_cli_params( $params ) {
        
        if( @$params['logfile'] ) {
            mylogger()->set_log_file( $params['logfile'] );
            mylogger()->echo_log = false;
        }
               
        if( !@$params['api'] ) {
            $params['api'] = 'toshi';
        }

        if( !@$params['report-type'] ) {
            $params['report-type'] = 'tx';
        }
        
        if( !@$params['cost-method'] ) {
            $params['cost-method'] = 'fifo';
        }

        $params['include-transfer'] = isset( $params['include-transfer'] );
        $params['disable-transfer'] = isset( $params['disable-transfer'] );

        if( !@$params['insight'] ) {
            $params['insight'] = 'https://insight.bitpay.com';
        }
        
        $params['toshi-fast'] = isset($params['toshi-fast']);
        $params['testnet'] = isset($params['testnet']);
        
        if( !@$params['toshi'] ) {
            $params['toshi'] = 'https://bitcoin.toshi.io';
        }

        if( !@$params['btcd-rpc-host'] ) {
            $params['btcd-rpc-host'] = '127.0.0.1';  // use localhost
        }
        
        if( !@$params['btcd-rpc-port'] ) {
            $params['btcd-rpc-port'] = 8334;  // use default port.
        }
        
        if( $params['api'] == 'btcd' && (!@$params['btcd-rpc-user'] || !@$params['btcd-rpc-pass']) ) {
            echo( "btcd-rpc-user and btcd-rpc-pass must be set when using api=btcd\n" );
            return 1;
        }

        if( !@$params['addr-tx-limit'] ) {
            $params['addr-tx-limit'] = 1000;
        }
        
        $params['direction'] = @$params['direction'] ?: 'both';
        if( !in_array( @$params['direction'], array( 'in', 'out', 'both' ) ) ) {
            $params['direction'] = 'both';
        }
        
        $params['date-start'] = @$params['date-start'] ? strtotime($params['date-start']) : 0;
        $params['date-end'] = @$params['date-end'] ? strtotime($params['date-end']) : time();
        
        $params['currency'] = strtoupper( @$params['currency'] ) ?: 'USD';
        
        $params['format'] = @$params['format'] ?: 'txt';

        $params['oracle-raw'] = @$params['oracle-raw'] ?: null;
        $params['oracle-json'] = @$params['oracle-json'] ?: null;

        // note: get_cols internally calls get_params and uses params[currency]
        $this->params = $params;        
        $params['cols'] = $this->get_cols( @$params['cols'] ?: 'standard' );
        
        $this->params = $params;

        if( isset( $params['version'] ) ) {
            $this->print_version();
            return 2;
        }
        
        if( isset( $params['list-templates'] ) ) {
            $this->print_list_templates();
            return 2;
        }
        if( isset( $params['list-cols'] ) ) {
            $this->print_list_cols();
            return 2;
        }        
        
        // these three are mutually exclusive.
        $cnt = 0;
        $cnt += @$params['addresses'] ? 1 : 0;
        $cnt += @$params['addressfile'] ? 1 : 0;
        $cnt += @$params['txfile'] ? 1 : 0;
        
        if( $cnt != 1 ) {
            $this->print_help();
            return 1;
        }
        
        if( !isset($params['g']) ) {
            $this->print_help();
            return 1;
        }
        
                
        return 0;
    }

    /**
     * returns the normalized CLI params, after initial processing/sanitization.
     */
    protected function get_params() {
        return $this->params;
    }

    /**
     * obtains the BTC addresses from user input, either via the
     *    --addresses arg or the --addressfile arg.
     */
    protected function get_addresses() {
        // optimize retrieval.
        static $addresses = null;
        if( $addresses ) {
            return $addresses;
        }
        
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
            throw new Exception( "No valid addresses to process.", 2 );
        }
        $addresses = $list;
        return $list;
    }

    /**
     * obtains transactions in libratax CSV format when --txfile flag
     * is present.
     */
    protected function gettxfromuser() {
        
        $params = $this->get_params();
        $txfile = @$params['txfile'];
        if( !$txfile ) {
            return null;
        }
        
        $start_time = $params['date-start'];
        $end_time = $params['date-end'] + 86400 -1;
        
        $cb = function( $row, $count ) use(&$start_time, &$end_time) {
            
            $txtime = strtotime($row['date']);
            
            $in_period = $txtime >= $start_time && $txtime <= $end_time;
            if( !$in_period ) {
                return null;
            }
            
            // note: libratax does not include an address for all transactions.
            return array( 'block_time' => $txtime,
                          'addr' => $row['dest'],
                          'amount' => $row['amt'],
                          'amount_in' => $row['amt'] > 0 ? $row['amt'] : 0,
                          'amount_out' => $row['amt'] < 0 ? abs($row['amt']) : 0,
                          'txid' => $count,
                          'exchange_rate' => $row['spotval'],
                          'type' => $row['taxtype'],
                        );        
        };
        
        return $this->getlibrataxcsv($txfile, $cb);
    }

    /**
     * parses a libratax transaction CSV file.
     */
    protected function getlibrataxcsv( $txfile, $row_cb = null ) {

        $lines = file( $txfile );
        array_shift($lines); // remove header row
        $rows = [];
        foreach( $lines as $l ) {
            list( $date, $dest, $note, $amt, $asset, $spotval, $totalval, $taxtype, $category ) = str_getcsv( $l );
            $row = [
                'date' => $date,
                'dest' => $dest,
                'note' => $note,
                'amt' => btcutil::btc_to_int($amt),
                'asset' => $asset,
                'spotval' => ((int)($spotval*1000))/10,
                'totalval' => $totalval,
                'taxtype' => $taxtype,
                'category' => $category
            ];
            $row = $row_cb ? $row_cb( $row, count($rows) + 1 ) : $row;
            if( $row ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * returns a key/val array of available column template definitions.
     * column templates are simply named lists of columns that should appear
     * in the tx report.
     */
    protected function get_col_templates() {
        $all_cols = implode( ',', array_keys( $this->all_columns() ) );
        $map = array(
            'standard' => array( 'desc' => "Standard report", 'cols' => 'date,addrshort,btcamount,price,fiatamount,fiatamountnow,fiatgain,type' ),
            'balance' => array( 'desc' => "Balance report", 'cols' => 'date,addrshort,btcin,btcout,realizedgain,btcbalance', 'notes' => 'Equivalent to LibraTax: Balance report.' ),
            'realizedgain' => array( 'desc' => "Realized Gain", 'cols' => 'date,btcamount,fiatamount,realizedgainshort,realizedgainlong' ),
            'realizedgainmethods' => array( 'desc' => "Realized Gain Method Comparison", 'cols' => 'date,btcamount,fiatamount,realizedgainfifo,realizedgainlifo' ),
            'thenandnow' => array( 'desc' => "Then and Now", 'cols' => 'date,price,fiatamount,pricenow,fiatamountnow,fiatgain' ),
            'inout' => array( 'desc' => "Standard report with Inputs and Outputs", 'cols' => 'date,addrshort,btcin,btcout,price,fiatin,fiatout' ),
            'blockchain' => array( 'desc' => "Only columns from blockchain", 'cols' => 'date,time,tx,address,btcin,btcout' ),
            'all' => array( 'desc' => "All available columns", 'cols' => $all_cols ),
        );
        foreach( $map as $k => $info ) {
            $map[$k]['cols'] = explode( ',', $info['cols'] );
        }
        return $map;
    }

    /**
     * parses the --cols argument and returns an array of columns.
     * note that the --cols argument accepts either:
     *   a csv list of columns   -- or --
     *   a template name + csv list of columns.
     *   
     * For the latter case, the template name is expanded to a column list.
     */
    protected function get_cols( $arg ) {
        $arg = $this->strip_whitespace( $arg );
        
        $templates = $this->get_col_templates();
        $allcols = $this->all_columns();

        $parts = explode( ',', $arg, 2 );
        $report = $parts[0];
        $extra = @$parts[1];
        if( @$templates[$report] ) {
            $arg = implode(',', $templates[$report]['cols']);
            if( $extra ) {
                $arg .= ',' . $extra;
            }
        }
        $cols = explode( ',', $arg );
        foreach( $cols as $c ) {
            if( !isset($allcols[$c]) ) {
                throw new Exception( "'$c' is not a known column or column template.", 2 );
            }
        }
        return $cols;
    }

    /**
     * removes whitespace from a string
     */
    protected function strip_whitespace( $str ) {
        return preg_replace('/\s+/', '', $str);
    }

    /**
     * a function to append strings and add newlines+indent as necessary.
     * TODO: save elsewhere. no longer used.
     */
    public function str_append_indent( $str, $append, $prefix, $maxlinechr = 80 ) {
        $lines = explode( "\n", $str );
        $lastline = $lines[count($lines)-1];
        $exceeds = strlen($lastline) + strlen($append) > $maxlinechr;
        $str .= $exceeds ? ("\n" . $prefix . $append) : $append;
        return $str;
    }

    /**
     * prints help text for --list-templates
     * note: output is pretty JSON for both human and machine readability.
     */
    public function print_list_templates() {
        $tpl = $this->get_col_templates();
        echo json_encode( $tpl, JSON_PRETTY_PRINT )  . "\n\n";
    }

    /**
     * prints help text for --list-cols
     * note: output is pretty JSON for both human and machine readability.
     */
    public function print_list_cols() {
        $map = $this->all_columns();
        $colmap = [];
        foreach( $map as $k => $v ) {
            $colmap[$k] = $v['title'];
        }
        echo json_encode( $colmap, JSON_PRETTY_PRINT ) . "\n\n";
    }

    /**
     * prints program version text
     */
    public function print_version() {
        $version = @file_get_contents(  __DIR__ . '/VERSION');
        echo $version ?: 'version unknown' . "\n";
    }    
    
    /**
     * prints CLI help text
     */
    public function print_help() {
         
        $buf = <<< END

   bitprices.php

   This script generates a report of transactions with the USD value
   at the time of each transaction.

   Options:

    -g                   go!
    
    --addresses=<csv>    comma separated list of bitcoin addresses
    --addressfile=<path> file containing bitcoin addresses, one per line.
    --txfile=<path>      file containing transactions in libratax csv format.
    
                         note: addresses, addressfile and txfile are exclusive.
    
    --api=<api>          toshi|btcd|insight.   default = toshi.
    
    --direction=<dir>    transactions in | out | both   default = both.
    
    --include-transfer   include transfers between wallet addresses
                           eg change amounts.
    --disable-transfer   disables transfer detection.  same behavior
                           as bitprices v1.0.3 and below.
    
    --date-start=<date>  Look for transactions since date. default = all.
    --date-end=<date>    Look for transactions until date. default = now.
    
    --currency=<curr>    symbol supported by bitcoinaverage.com.  default = USD.
    
    --report-type=<type> tx | schedule_d | matrix.    default=tx
                           options --direction, --cols, --list-templates,
                                   --list-cols apply to tx report only.
                           option  --cost-method applies to schedule_d and
                                   matrix reports only.
                              
    --cost-method=<m>    fifo|lifo  default = fifo.
    
    --cols=<cols>        a report template or list of columns. default=standard.
                         See --list-cols
                         
    --list-templates     if present, a list of templates will be printed.
    --list-cols          if present, a list of columns will be printed.
    
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
    
    --oracle-raw=<p>    path to save raw server response, optional.
    --oracle-json=<p>   path to save formatted server response, optional.
    


END;

   fprintf( STDERR, $buf );       
        
    }

    /**
     * processes transactions and price data for one or more bitcoin addresses.
     */
    protected function process_transactions( $trans ) {
        
        $params = $this->get_params();
        $currency = $params['currency'];
        
        // make vin and vout maps keyed by txid for fast lookups.
        // this will give us the sum of wallet-address inputs and
        // sum of wallet-address outputs, per transaction.
        $vinlist = [];
        $voutlist = [];
        foreach( $trans as $tx ) {
            if( $tx['amount_out'] ) {
                $key = $tx['txid'];
                $sum = @$vinlist[$key] ?: 0;
                $vinlist[$key] = $sum + $tx['amount_out'];
            }
            else if( $tx['amount_in'] ) {
                $key = $tx['txid'];
                $sum = @$voutlist[$key] ?: 0;
                $voutlist[$key] = $sum + $tx['amount_in'];
            }
        }
        
        $results = array();
        foreach( $trans as $tx ) {
            
            if( !@$tx['type'] ) {  // libratax data already has type set.

                // determine transfer type.
                $type = '';
                if( $tx['amount_in'] ) {
                    $key = $tx['txid'];
                    $total_output = @$vinlist[$key];
                    if( $total_output && !$params['disable-transfer']) {
                        
                        $diff = $tx['amount_in'] - $total_output;
                        $vinlist[$key] -= $tx['amount_in'];
                        $vinlist[$key] = $vinlist[$key] >= 0 ?: 0;

                        if( $diff > 0 ) {
                            $tx['amount_in'] = $diff;  // purchase amount from 3rd party.
                            $type = 'purchase';

                            // Remainder is the internal transfer amount.
                            if( $params['include-transfer'] ) {
                                $tx_new = $tx;
                                $tx_new['amount_in'] = $total_output;
                                $tx_new['type'] = 'transfer';
                                $this->add_fields( $tx_new, $currency );
                                $results[] = $tx_new;
                            }
                        }
                        else {
                            $type = 'transfer';
                        }
                    }
                    else {
                        $type = 'purchase';
                    }
                }
                else if( $tx['amount_out'] ) {
                    $key = $tx['txid'];
                    $total_input = @$voutlist[$key];
                    if( $total_input && !$params['disable-transfer']) {
                        
                        $diff = $tx['amount_out'] - $total_input;
                        $voutlist[$key] -= $tx['amount_out'];
                        $voutlist[$key] = $voutlist[$key] >= 0 ?: 0;
                        
                        if( $diff > 0 ) {
                            $tx['amount_out'] = $diff;  // sale amount to 3rd party.
                            $type = 'sale';
                            
                            // Remainder is the internal transfer amount.
                            if( $params['include-transfer'] ) {
                                $tx_new = $tx;
                                $tx_new['amount_out'] = $total_input;
                                $tx_new['type'] = 'transfer';
                                $this->add_fields( $tx_new, $currency );
                                $results[] = $tx_new;
                            }
                        }
                        else {
                            $type = 'transfer';
                        }
                    }
                    else {
                        $type = 'sale';
                    }
                }
                $tx['type'] = $type;
            }

            $this->add_fields( $tx, $currency );
            
            // exclude transfers unless --include-transfers flag is present.
            if( $tx['type'] != 'transfer' || $params['include-transfer'] ) {
                $results[] = $tx;
            }
        }

        // important:  for LIFO, the movements must be sorted by timestamp
        //             and purchase type.  (buy then sell).  If a SELL were to
        //             precede a BUY for the same timestamp, then the BUY would
        //             be processed by LIFO as if it occurred AFTER the sell.
        usort( $results, function($a, $b) {
            // order by block_time asc
            if( $a['block_time'] < $b['block_time'] ) {
                return -1;
            }
            else if( $a['block_time'] > $b['block_time'] ) {
                return 1;
            }
        
            // order by buy, then sell, then transfer.
            if( $a['type'] != $b['type'] ) {
                $rc = strcmp( $a['type'], $b['type'] );
                if( $rc != 0 ) {
                    return $rc;
                }
            }
            
            return strcmp( $a['txid'], $b['txid'] );
        });

        
        return $results;
    }
    
    protected function add_fields( &$tx, $currency ) {

        $er = @$tx['exchange_rate'] ?: $this->get_historic_price( $currency, $tx['block_time'] );
        
        $tx['exchange_rate'] = $er;
        $tx['exchange_rate_now'] = $ern = $this->get_24_hour_avg_price_cached( $currency );
        
        $tx['fiat_amount_in'] = $er ? btcutil::btcint_to_fiatint( $tx['amount_in'] * $tx['exchange_rate'] ) : null;
        $tx['fiat_amount_out'] = $er ? btcutil::btcint_to_fiatint( $tx['amount_out'] * $tx['exchange_rate'] ) : null;
        
        $tx['fiat_amount_in_now'] = $ern ? btcutil::btcint_to_fiatint( $tx['amount_in'] * $tx['exchange_rate_now'] ) : null;
        $tx['fiat_amount_out_now'] = $ern ? btcutil::btcint_to_fiatint( $tx['amount_out'] * $tx['exchange_rate_now'] ) : null;
        
        $tx['fiat_currency'] = $currency;
        
    }
    
    /**
     * queries a blockchain api provider to obtain historical transactions for
     * list of input addresses.
     */
    protected function get_matching_transactions( $addrs ) {
        $params = $this->get_params();

        $api = blockchain_api_factory::instance( $params['api'] );
        $tx_list = $api->get_addresses_transactions( $addrs,
                                                     $params['date-start'],
                                                     $params['date-end'] +3600*24-1,
                                                     $params );
        
        return $tx_list;
    }

     /**
      * returns price for currency on UTC date of $timestamp
      */
    protected function get_historic_price( $currency, $timestamp ) {
        $date = gmdate( 'Y-m-d', $timestamp );
        
        $map = self::get_historic_prices( $currency );
        $price = @$map[$date];
        
        // if date is today, then get 24 hour average.
        if( !$price && time() - $timestamp < 86400 ) {
            return $this->get_24_hour_avg_price_cached( $currency );
        }
        
        return $price;
    }

    /**
     * retrieves the 24 hour avg price from cache if present and not stale.
     * stale is defined as 1 hour.
     */
    protected function get_24_hour_avg_price_cached( $currency ) {
        static $prices = array();

        $price = @$prices[$currency];
        if( $price ) {
            return $price;
        }

        $fname = dirname(__FILE__) . sprintf( '/price_24/24_hour_avg_price.%s.csv', $currency );
        $max_age = 60 * 60;  // max 1 hour.
        $cache_file_valid = file_exists( $fname ) && time() - filemtime( $fname ) < $max_age;

        // use cached price file if file age is less than max_age
        if( $cache_file_valid ) {
            $price = unserialize( file_get_contents( $fname ) );
            $prices[$currency] = $price;
            return $price;
        }

        $dir = dirname( $fname );
        file_exists($dir) || mkdir( $dir );

        $price = $this->get_24_hour_avg_price( $currency );
        
        @unlink( $fname );
        file_put_contents( $fname, serialize($price) );

        $prices[$currency] = $price;

        return $price;
    }

    /**
     * retrieves the avg price for currency during the past 24 hours.
     * TODO: abstract for multiple providers.
     */
    protected function get_24_hour_avg_price( $currency ) {

        $url_mask = 'https://api.bitcoinaverage.com/ticker/global/%s/';
        $url = sprintf( $url_mask, strtoupper( $currency ) );
        
        mylogger()->log( "Retrieving $currency 24 hour average price from bitcoinaverage.com", mylogger::info );
        $buf = file_get_contents( $url );
        $data = json_decode( $buf, true );
        return (int)($data['24h_avg'] * 100);
    }

    /**
     * retrieves all historic prices for $currency, from cache if present and
     * not stale.  stale is defined as older than 12 hours.
     * TODO: abstract for multiple providers.
     */
    protected static function get_historic_prices($currency) {
        
        static $maps = array();
        static $downloaded_map = array();
        
        $map = @$maps[$currency];
        if( $map ) {
            return $map;
        }

        // if we already downloaded this run, then abort.
        $downloaded = @$downloaded_map[$currency];
        if( $downloaded ) {
            return null;
        }
        
        $fname = dirname(__FILE__) . sprintf( '/price_history/per_day_all_time_history.%s.csv', $currency );
        $exists = file_exists( $fname );
        if( $exists ) {
            $file_age = time() - filemtime( $fname );
        }
        
        if( !$exists || $file_age > 60*60*12 ) {
            $dir = dirname( $fname );
            file_exists($dir) || mkdir( $dir );
            
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
            $map[$date] = (int)($avg * 100);
        }
        $maps[$currency] = $map;
  
        return $map;
    }

    /**
     * shortens a bitcoin address to abc...xyz form.
     */
    protected function shorten_addr( $address ) {
        return strlen( $address ) > 8 ? substr( $address, 0, 3 ) . '..' . substr( $address, -3 ) : $address;
    }

    /**
     * generates the tx (transaction) report.
     */
    protected function gen_report_tx( $results, $format ) {
        
        $params = $this->get_params();
        $direction = $params['direction'];
        
        $btc_balance = 0;
        $fiat_balance = 0;
        $fiat_balance_now = 0;
        $fiat_gain_balance = 0;
        
        $total_btc_in = 0 ; $total_fiat_in = 0;  $num_tx_in = 0;  // for average cost.
        $total_btc_out = 0; $total_fiat_out = 0;  $num_tx_out = 0;
        
        $total_btc_in_alltime = 0 ; $total_fiat_in_alltime = 0;  $num_tx_in_alltime = 0;  // for average cost.
        $total_btc_out_alltime = 0; $total_fiat_out_alltime = 0;  $num_tx_out_alltime = 0;
        
        $short_term_gain = $long_term_gain = 0;
        
        $exchange_rate = 0;
        
        $fifo_stack = array();
        $lifo_stack = array();

        $total_fiat_in = $total_btc_in = 0;
        
        $fifo_lot_id = 0;
        $lifo_lot_id = 0;

        $col_totals = array();
        $map = $this->all_columns();        

        $nr = [];
        $metalist = [];
        foreach( $results as $r ) {

            $realized_gain_fifo_short = $realized_gain_fifo_long = 0;
            $realized_gain_lifo_short = $realized_gain_lifo_long = 0;
            
            $btc_amount = $r['amount_in'] - $r['amount_out'];
            $fiat_amount = $r['fiat_amount_in'] - $r['fiat_amount_out'];
            $fiat_amount_now = $r['fiat_amount_in_now'] - $r['fiat_amount_out_now'];
            
            $fifo_qty = $lifo_qty = $btc_amount;
            
            $fiat_gain = $fiat_amount_now - $fiat_amount;
            
            $btc_balance += $btc_amount;
            $fiat_balance += $fiat_amount;
            $fiat_balance_now += $fiat_amount_now;
            $fiat_gain_balance += $fiat_gain;
            
            if( $r['type'] == 'purchase' ) {
                // add to end of fifo stack
                $fifo_stack[] = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'], 'lot_id' => ++$fifo_lot_id );
                $lifo_stack[] = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'], 'lot_id' => ++$lifo_lot_id );
            }

            // calc realized gains if it is an output.
            // TODO: avoid if a transfer between our wallet addresses.
            if( $r['type'] == 'sale' ) {

                // calc fifo totals to date
                $this->calc_fifo_stack( $r, $fifo_stack, $is_fifo = true,
                                       function( $data )
                                        use (&$realized_gain_fifo_short, &$realized_gain_fifo_long ) {

                    $realized_gain_fifo_short  += $data['longterm'] ? 0 : $data['realized_gain'];
                    $realized_gain_fifo_long  += $data['longterm'] ? $data['realized_gain'] : 0;
                } );

                // calc lifo totals to date
                $this->calc_fifo_stack( $r, $lifo_stack, $is_fifo = false,
                                       function( $data )
                                        use (&$realized_gain_lifo_short, &$realized_gain_lifo_long ) {

                    $realized_gain_lifo_short  += $data['longterm'] ? 0 : $data['realized_gain'];
                    $realized_gain_lifo_long  += $data['longterm'] ? $data['realized_gain'] : 0;
                } );
            }
            
            $realized_gain_fifo = $realized_gain_fifo_long + $realized_gain_fifo_short;
            $realized_gain_lifo = $realized_gain_lifo_long + $realized_gain_lifo_short;
 
            // calc alltime totals.
            if( $r['amount_in'] ) {
                $total_fiat_in_alltime += $r['fiat_amount_in'];
                $total_btc_in_alltime += $r['amount_in'];
                $num_tx_in_alltime ++;
            }
            if( $r['amount_out'] ) {
                $total_fiat_out_alltime += $r['fiat_amount_out'];
                $total_btc_out_alltime += $r['amount_out'];
                $num_tx_out_alltime ++;
            }
            
            // filter out transactions by direction and date params.
            if( $direction == 'in' && !$r['amount_in'] ) {
                continue;
            }
            else if( $direction == 'out' && !$r['amount_out'] ) {
                continue;
            }
            else if( $r['block_time'] < $params['date-start'] ) {
                continue;
            }
            else if( $r['block_time'] > $params['date-end'] +3600*24-1 ) {
                continue;
            }
            
            $exchange_rate = $r['exchange_rate'];
            
            if( $r['amount_in'] ) {
                $total_fiat_in += $r['fiat_amount_in'];
                $total_btc_in += $r['amount_in'];
                $num_tx_in ++;
            }

            $btc_out_amount = $r['amount_out'] - $r['amount_in'];
            $fiat_out_amount = $r['fiat_amount_out'] - $r['fiat_amount_in'];

            if( $r['amount_out'] ) {
                $total_btc_out += $r['amount_out'];
                $total_fiat_out += $r['fiat_amount_out'];

                $num_tx_out ++;
            }
            
            $fc = strtoupper( $r['fiat_currency'] );
                       
            $row = [];
            $meta = [];

            $meta['addr'] = $r['addr'];
            $meta['tx'] = $r['txid'];
            
            $methods = array('fifo', 'lifo');
            if( !in_array( $params['cost-method'], $methods ) ) {
                throw new Exception( "Invalid cost method: " . $params['cost-method'] );
            }
            $cm = $params['cost-method'];
            
            $realized_gain = eval("return \$realized_gain_{$cm};");
            $realized_gain_long = eval("return \$realized_gain_{$cm}_long;" );
            $realized_gain_short = eval("return \$realized_gain_{$cm}_short;" );

            foreach( $params['cols'] as $col ) {
                $cn = $map[$col]['title'];   // column name
                switch( $col ) {
                    case 'date': $row[$cn] = date('Y-m-d', $r['block_time'] ); break;
                    case 'time': $row[$cn] = date('H:i:s', $r['block_time'] ); break;
                    case 'addrshort': $row[$cn] = $this->shorten_addr( $r['addr'] ); break;
                    case 'address': $row[$cn] = $r['addr']; break;
                    case 'btcin': $row[$cn] = btcutil::btc_display( $r['amount_in'], true ); break;
                    case 'btcout': $row[$cn] = btcutil::btc_display( $r['amount_out'], true ); break;
                    case 'btcbalance': $row[$cn] = btcutil::btc_display( $btc_balance ); break;
                    case 'fiatin': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_in'], true ); break;
                    case 'fiatout': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_out'], true ); break;
                    case 'fiatbalance': $row[$cn] = btcutil::fiat_display( $fiat_balance ); break;
                    case 'fiatinnow': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_in_now'], true ); break;
                    case 'fiatoutnow': $row[$cn] = btcutil::fiat_display( $r['fiat_amount_out_now'], true ); break;
                    case 'fiatbalancenow': $row[$cn] = btcutil::fiat_display( $fiat_balance_now ); break;
                    case 'price': $row[$cn] = btcutil::fiat_display( $r['exchange_rate'] ); break;
                    case 'pricenow': $row[$cn] = btcutil::fiat_display( $r['exchange_rate_now'] ); break;
                        
                    case 'btcamount': $row[$cn] = btcutil::btc_display( $btc_amount ); break;
                    case 'fiatamount': $row[$cn] = btcutil::fiat_display( $fiat_amount ); break;
                    case 'fiatamountnow': $row[$cn] = btcutil::fiat_display( $fiat_amount_now ); break;

                    case 'fiatgain': $row[$cn] = btcutil::fiat_display( $fiat_gain ); break;
                    case 'fiatgainbalance': $row[$cn] = btcutil::fiat_display( $fiat_gain_balance ); break;

                    case 'realizedgain': $row[$cn] = btcutil::fiat_display( $realized_gain, true ); break;
                    case 'realizedgainlong': $row[$cn] = btcutil::fiat_display( $realized_gain_long, true ); break;
                    case 'realizedgainshort': $row[$cn] = btcutil::fiat_display( $realized_gain_short, true ); break;
                    
                    case 'realizedgainfifo': $row[$cn] = btcutil::fiat_display( $realized_gain_fifo, true ); break;
                    case 'realizedgainfifolong': $row[$cn] = btcutil::fiat_display( $realized_gain_fifo_long, true ); break;
                    case 'realizedgainfifoshort': $row[$cn] = btcutil::fiat_display( $realized_gain_fifo_short, true ); break;
                        
                    case 'realizedgainlifo': $row[$cn] = btcutil::fiat_display( $realized_gain_lifo, true ); break;
                    case 'realizedgainlifolong': $row[$cn] = btcutil::fiat_display( $realized_gain_lifo_long, true ); break;
                    case 'realizedgainlifoshort': $row[$cn] = btcutil::fiat_display( $realized_gain_lifo_short, true ); break;

                    case 'type': $row[$cn] = $r['type']; break;
                    case 'txshort': $row[$cn] = $this->shorten_addr( $r['txid'] ); break;
                    case 'tx': $row[$cn] = $r['txid']; break;
                }
                if( $map[$col]['total'] ) {
                    $total = @$col_totals[$col] ?: 0;
                    $col_totals[$col] = $total + $row[$cn];
                }
            }
            $nr[] = $row;
            $metalist[] = $meta;
        }
        
        // Add Totals Row
        $found_empty = false;
        $row = [];
        foreach( $params['cols'] as $col ) {
            $cn = $map[$col]['title'];   // column name
            if( !$found_empty && !$map[$col]['total'] ) {
                $row[$cn] = 'Totals:';
                $found_empty = true;
            }
            else if( isset( $col_totals[$col] ) ) {
                $row[$cn] = strstr( $col, 'btc' ) ? btcutil::btc_display( btcutil::btc_to_int( $col_totals[$col] ) ) :
                                                    btcutil::fiat_display( btcutil::fiat_to_int( $col_totals[$col] ) );
            }
            else {
                $row[$cn] = null;
            }
        }
        $nr[] = $row;

        return array( $nr, $metalist );
    }

    /**
     * calculate realized gains using fifo or lifo method.
     */
    protected function calc_fifo_stack( $r, &$fifo_stack, $is_fifo, $callback ) {
        $params = $this->get_params();

        $out = $r['amount_out'];
        
        while( $out > 0 && count($fifo_stack) ) {
            $first =& $fifo_stack[0];
            if( !$is_fifo ) {
                $first =& $fifo_stack[count($fifo_stack)-1];
            }

            $age = $r['block_time'] - $first['block_time'];
            $longterm = $age > 31536000;  // 1 year.  86400 * 365;
            
            $orig_qty = $first['qty'];
            $orig_exchange_rate = $first['exchange_rate'];
            $lot_id = $first['lot_id'];
            if( $out < $first['qty'] ) {
                $qty = $out;
                $proceeds = $qty * $r['exchange_rate'];
                $cost_basis = $qty * $first['exchange_rate'];
                $realized_gain = $proceeds - $cost_basis;
                $first['qty'] -= $out;
                $out = 0;
            }
            else {
                $qty = $first['qty'];
                $proceeds = $qty * $r['exchange_rate'];
                $cost_basis = $qty * $first['exchange_rate'];
                $realized_gain = $proceeds - $cost_basis;
                $out -= $first['qty'];
                $is_fifo ? array_shift( $fifo_stack ) : array_pop( $fifo_stack );
            }

            $data = array(
                'lot_id' => $lot_id,
                'date_acquired' => $first['block_time'],
                'date_sold' => $r['block_time'],
                'exchange_rate' => $r['exchange_rate'],
                'orig_exchange_rate' => $orig_exchange_rate,
                'qty' => $qty,
                'orig_qty' => $orig_qty,
                'proceeds' => btcutil::btcint_to_fiatint( $proceeds ),
                'cost_basis' => btcutil::btcint_to_fiatint( $cost_basis ),
                'realized_gain' => btcutil::btcint_to_fiatint( $realized_gain ),
                'longterm' => $longterm,
                );
                $callback( $data );
        }
    }

    /**
     * generates a report in schedule D Form 8949 format.
     */
    protected function gen_report_schedule_d( $results, $format ) {
        
        $params = $this->get_params();
        $cost_method = $params['cost-method'];
        
        $fifo_stack = array();
        $fifo_lot_id = 0;

        $totals = array( 'short' => array( 'proceeds' => 0, 'cost_basis' => 0, 'gain' => 0),
                         'long'  => array( 'proceeds' => 0, 'cost_basis' => 0, 'gain' => 0) );

        $nr = array();

        foreach( $results as $r ) {
            
            if( $r['type'] == 'purchase' ) {
                $fifo_stack[] = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'], 'lot_id' => ++$fifo_lot_id );
            }

            if( $r['type'] == 'sale' ) {
                
                $is_fifo = $cost_method == 'fifo';
                $this->calc_fifo_stack( $r, $fifo_stack, $is_fifo,
                                       function( $data ) use (&$nr, &$totals) {
                    $longterm = $data['longterm'];
                    $proceeds = $data['proceeds'];
                    $cost_basis = $data['cost_basis'];
                    $gain_or_loss = $data['realized_gain'];
                    
                    $description_of_property = btcutil::btc_display( $data['qty'] ) . ' Bitcoins';
                    
                    if( $longterm ) {
                        $totals['long']['proceeds'] += $proceeds;
                        $totals['long']['cost_basis'] += $cost_basis;
                        $totals['long']['gain'] += $gain_or_loss;
                    }
                    else {
                        $totals['short']['proceeds'] += $proceeds;
                        $totals['short']['cost_basis'] += $cost_basis;
                        $totals['short']['gain'] += $gain_or_loss;
                    }                    

                    $row['Description'] = $description_of_property;
                    $row['Date Acquired'] = gmdate('Y-m-d', $data['date_acquired'] );
                    $row['Date Sold/Disposed'] = gmdate('Y-m-d', $data['date_sold'] );
                    $row['Proceeds'] = btcutil::fiat_display( $proceeds );
                    $row['Cost Basis'] = btcutil::fiat_display( $cost_basis );
                    $row['Gain/Loss'] = btcutil::fiat_display( $gain_or_loss );
                    $row['Short/Long-term'] = $longterm ? 'Long' : 'Short';
                    
                    $nr[] = $row;
                } );
            }
        }

        // Add Net Summary Long row 
        $row['Description'] = '';
        $row['Date Acquired'] = '';
        $row['Date Sold/Disposed'] = 'Net Summary Long:';
        $row['Proceeds'] = btcutil::fiat_display( $totals['long']['proceeds'] );
        $row['Cost Basis'] = btcutil::fiat_display( $totals['long']['cost_basis'] );
        $row['Gain/Loss'] = btcutil::fiat_display( $totals['long']['gain'] );
        $row['Short/Long-term'] = '';
        $nr[] = $row;
    
        // Add Net Summary Short row 
        $row['Description'] = '';
        $row['Date Acquired'] = '';
        $row['Date Sold/Disposed'] = 'Net Summary Short:';
        $row['Proceeds'] = btcutil::fiat_display( $totals['short']['proceeds'] );
        $row['Cost Basis'] = btcutil::fiat_display( $totals['short']['cost_basis'] );
        $row['Gain/Loss'] = btcutil::fiat_display( $totals['short']['gain'] );
        $row['Short/Long-term'] = '';
        $nr[] = $row;
        
        return $nr;
    }

    /**
     * generates a report in libratax matrix format.
     */
    protected function gen_report_matrix( $results, $format ) {
        
        $params = $this->get_params();
        $cost_method = $params['cost-method'];
        
        $fifo_stack = array();
        $fifo_lot_id = 0;
        $proceeds_total_long = $proceeds_total_short = 0;
        $cost_basis_total_long = $cost_basis_total_short = 0;
        $gain_or_loss_total_long = $gain_or_loss_total_short = 0;
                
        $nr = array();
        
        foreach( $results as $r ) {
            
            if( $r['type'] == 'purchase' ) {
                $fifo_stack[] = array( 'qty' => $r['amount_in'], 'exchange_rate' => $r['exchange_rate'], 'block_time' => $r['block_time'], 'lot_id' => ++$fifo_lot_id );
            }

            if( $r['type'] == 'sale' ) {
                
                $is_fifo = $cost_method == 'fifo';
                $this->calc_fifo_stack( $r, $fifo_stack, $is_fifo,
                                       function( $data )
                                        use (&$proceeds_total_short, &$proceeds_total_long, &$nr,
                                             &$cost_basis_total_short, &$cost_basis_total_long,
                                             &$gain_or_loss_total_short, &$gain_or_loss_total_long ) {
                    
                    $row['Purchase Lot ID'] = $data['lot_id'];
                    $row['Date Purchased'] = gmdate('Y-m-d', $data['date_acquired'] );
                    $row['Original Amount'] = btcutil::btc_display( $data['orig_qty'] );
                    $row['Amount Sold'] = btcutil::btc_display( $data['qty'] );
                    $row['Cost Basis Price'] = btcutil::fiat_display( $data['orig_exchange_rate'] );
                    $row['Total Cost Basis'] = btcutil::fiat_display( $data['cost_basis'] );
                    $row['Date Sold'] = gmdate('Y-m-d', $data['date_sold'] );
                    $row['Sale Value Price'] = btcutil::fiat_display( $data['exchange_rate'] );
                    $row['Total Sale Value'] = btcutil::fiat_display( $data['proceeds'] );
                    $row['Realized Gain'] = btcutil::fiat_display( $data['realized_gain'] );
                    $row['Short/Long'] = $data['longterm'] ? 'Long' : 'Short';
                    
                    $nr[] = $row;
                } );
            }
        }
        return $nr;
    }
    
    

    /**
     * returns all available columns for standard report.
     */
    protected function all_columns() {
        $params = $this->get_params();
        $curr = $params['currency'];
        return array( 
            'date'                   => array( 'title' => 'Date',                         'total' => false ),
            'time'                   => array( 'title' => 'Time',                         'total' => false ),
            'addrshort'              => array( 'title' => 'Addr Short',                   'total' => false ),
            'address'                => array( 'title' => 'Address',                      'total' => false ),
            'btcin'                  => array( 'title' => 'BTC In',                       'total' => true ),
            'btcout'                 => array( 'title' => 'BTC Out',                      'total' => true ),
            'btcbalance'             => array( 'title' => 'BTC Balance',                  'total' => false ),
            'fiatin'                 => array( 'title' => $curr . ' In',                  'total' => true ),
            'fiatout'                => array( 'title' => $curr . ' Out',                 'total' => true ),
            'fiatbalance'            => array( 'title' => $curr . ' Balance',             'total' => false ),
            'fiatinnow'              => array( 'title' => $curr . ' In',                  'total' => true ),
            'fiatoutnow'             => array( 'title' => $curr . ' Out',                 'total' => true ),   
            'fiatbalancenow'         => array( 'title' => $curr . ' Balance Now',         'total' => false ),  
            'price'                  => array( 'title' => $curr . ' Price',               'total' => false ),   
            'pricenow'               => array( 'title' => $curr . ' Price Now',           'total' => false ),
                
            'btcamount'              => array( 'title' => 'BTC Amount',                   'total' => true ),  
            'fiatamount'             => array( 'title' => $curr . ' Amount',              'total' => true ),
            'fiatamountnow'          => array( 'title' => $curr . ' Amount Now',          'total' => true ),  

            'fiatgain'               => array( 'title' => $curr . ' Gain',                'total' => true ),
            'fiatgainbalance'        => array( 'title' => $curr . ' Gain Balance',        'total' => false ),

            'realizedgain'           => array( 'title' => 'Realized Gain',                'total' => true ),
            'realizedgainlong'       => array( 'title' => 'Realized Gain (Long)',         'total' => true ),
            'realizedgainshort'      => array( 'title' => 'Realized Gain (Short)',        'total' => true ),
            
            'realizedgainfifo'       => array( 'title' => 'Realized Gain (FIFO)',         'total' => true ),  
            'realizedgainfifolong'   => array( 'title' => 'Realized Gain (FIFO, Long)',   'total' => true ),
            'realizedgainfifoshort'  => array( 'title' => 'Realized Gain (FIFO, Short)',  'total' => true ),
            
            'realizedgainlifo'       => array( 'title' => 'Realized Gain (LIFO)',         'total' => true ),  
            'realizedgainlifolong'   => array( 'title' => 'Realized Gain (LIFO, Long)',   'total' => true ),
            'realizedgainlifoshort'  => array( 'title' => 'Realized Gain (LIFO, Short)',  'total' => true ),
                
            'type'                   => array( 'title' => 'Type',                         'total' => false ),
            'txshort'                => array( 'title' => 'Tx Short',                     'total' => false ),
            'tx'                     => array( 'title' => 'Tx',                           'total' => false ),  
        );
    }

    /**
     * prints out single report in one of several possible formats,
     * or multiple reports, one for each possible format.
     */
    protected function print_results( $results, $meta, $format ) {
        $params = $this->get_params();
        $outfile = @$params['outfile'];
        
        if( $outfile && $format == 'all' ) {
            $formats = array( 'txt', 'csv', 'json', 'jsonpretty', 'html' );
            
            foreach( $formats as $format ) {
                
                $outfile = sprintf( '%s/%s.%s',
                                    pathinfo($outfile, PATHINFO_DIRNAME),
                                    pathinfo($outfile, PATHINFO_FILENAME),
                                    $format );
                
                report_writer::write_results( $results, $meta, $outfile, $format );
            }
        }
        else {
            report_writer::write_results( $results, $meta, $outfile, $format );
        }
    }
    
}


class report_writer {

    /**
     * prints out single report in specified format, either to stdout or file.
     */
    static public function write_results( $results, $meta, $outfile, $format ) {

        $fname = $outfile ?: 'php://stdout';
        $fh = fopen( $fname, 'w' );

        switch( $format ) {
            case 'txt':  self::write_results_fixed_width( $fh, $results ); break;
            case 'csv':  self::write_results_csv( $fh, $results ); break;
            case 'json':  self::write_results_json( $fh, $results ); break;
            case 'html':  self::write_results_html( $fh, $results, $meta ); break;
            case 'jsonpretty':  self::write_results_jsonpretty( $fh, $results ); break;
        }

        fclose( $fh );

        if( $outfile ) {
            echo "\n\nReport was written to $fname\n\n";
        }
    }

    /**
     * writes out results in json (raw) format
     */
    static public function write_results_json( $fh, $results ) {
        fwrite( $fh, json_encode( $results ) );
    }

    /**
     * writes out results in jsonpretty format
     */
    static public function write_results_jsonpretty( $fh, $results ) {
        fwrite( $fh, json_encode( $results,  JSON_PRETTY_PRINT ) );
    }
    
    /**
     * writes out results in csv format
     */
    static public function write_results_csv( $fh, $results ) {
        if( @$results[0] ) {
            fputcsv( $fh, array_keys( $results[0] ) );
        }
        
        foreach( $results as $row ) {
            fputcsv( $fh, $row );
        }
    }
    
    /**
     * writes out results in html format
     */
    static public function write_results_html( $fh, $results, $meta ) {
        for( $i = 0; $i < count($results); $i ++ ) {
            $row =& $results[$i];
            $addr = @$meta[$i]['addr'];
            $tx = @$meta[$i]['tx'];
            
            if( $addr && $tx ) {
                $addr_url = self::is_addr($addr) ? sprintf( 'http://blockchain.info/address/%s', $addr ) : null;
                $tx_url = self::is_txid($tx) ? sprintf( 'http://blockchain.info/tx/%s', $tx ) : null;
        
                if( isset( $row['Date'] ) && $tx_url ) {
                    $row['Date'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Date'] );
                }
                if( isset( $row['Addr Short'] ) && $addr_url ) {
                    $row['Addr Short'] = sprintf( '<a href="%s">%s</a>', $addr_url, $row['Addr Short'] );
                }
                if( isset( $row['Address'] ) && $addr_url ) {
                    $row['Address'] = sprintf( '<a href="%s">%s</a>', $addr_url, $row['Address'] );
                }
                if( isset( $row['Tx Short'] ) && $tx_url ) {
                    $row['Tx Short'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Tx Short'] );
                }
                if( isset( $row['Tx'] ) && $tx_url ) {
                    $row['Tx'] = sprintf( '<a href="%s">%s</a>', $tx_url, $row['Tx'] );
                }
            }
        }

        $table = new html_table();
        $table->header_attrs = array();
        $table->table_attrs = array( 'class' => 'bitprices bordered' );

        if( @$results[0] ) {
            $header = array_keys( $results[0] );
            $html = $table->table_with_header( $results, $header );
        }
        
        else {
           $results = [ ["No transactions found."] ];
            $html = $table->table( $results );
        }
        
        
        fwrite( $fh, $html );
    }

    /**
     * checks if string appears to be a bitcoin address.
     */
    static private function is_addr( $addr ) {
        return strlen($addr) >= 26 && strlen($addr) <= 35; 
    }

    /**
     * checks if string appears to be a txid.
     */
    static private function is_txid( $tx ) {
        return strlen($tx) == 64; 
    }
    
    /**
     * writes out results as a plain text table.  similar to mysql console results.
     */
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

// a utility class for btc and fiat conversions.
class btcutil {
    
    // converts btc decimal amount to integer amount.
    static public function btc_to_int( $val ) {
        return ((int)(($val * 100000000)*10))/10;
    }

    // converts btc integer amount to decimal amount with full precision.
    static public function int_to_btc( $val ) {
        return $val / 100000000;
    }

    // formats btc integer amount for display as decimal amount (rounded)   
    static public function btc_display( $val, $omit_zero = false ) {
        if( !$val && $omit_zero ) {
            return null;
        }
        return number_format( round($val / SATOSHI,8), 8, '.', '');
    }

    // formats usd integer amount for display as decimal amount (rounded)
    static public function fiat_display( $val, $omit_zero = false ) {
        if( !$val && $omit_zero ) {
            return null;
        }
        return number_format( round($val / 100,3), 2, '.', '');
    }
    
    // converts fiat decimal amount to integer amount.
    static public function fiat_to_int( $val ) {
        return ((int)(($val * 100)*10))/10;
    }

    // converts btc integer amount to decimal amount with full precision.
    static public function btcint_to_fiatint( $val ) {
        return round($val / 100000000, 0);
    }
    
}




