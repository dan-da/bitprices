<?php

interface price_api {

    /**
     * retrieves price history, all time.
     */
    function retrieve_price_history( $currency );

    /**
     * retrieves the avg price for currency during the past 24 hours.
     */
    function get_24_hour_avg_price( $currency );
}

class price_api_factory {
    static public function instance( $type ) {
        $type = trim($type);
        $class = 'price_api_' . $type;
        try {
            return new $class;
        }
        catch ( Exception $e ) {
            throw new Exception( "Invalid api provider '$type'" );
        }
    }
}


/**
 * An implementation of price_api that uses the bitcoinaverage.com oracle
 */
class price_api_btcaverage {
    
    public function retrieve_price_history( $currency ) {
        $market = 'BTC' . strtoupper($currency);
        $url_mask = 'https://apiv2.bitcoinaverage.com/indices/global/history/%s?period=alltime&format=csv';
        $url = sprintf( $url_mask, $market );
        mylogger()->log( "Retrieving $currency price history from $url", mylogger::info );
        $buf = file_get_contents( $url );
        return $buf;
    }

    /**
     * retrieves the avg price for currency during the past 24 hours.
     * TODO: abstract for multiple providers.
     */
    public function get_24_hour_avg_price( $currency ) {
        
        $market = 'BTC' . strtoupper($currency);
        $url_mask = 'https://apiv2.bitcoinaverage.com/indices/global/ticker/%s';
        $url = sprintf( $url_mask, $market );
        mylogger()->log( "Retrieving $currency price history from $url", mylogger::info );
        $buf = file_get_contents( $url );
        $data = json_decode( $buf, true );
        
        return $data['averages']['day'] * 100;
    }    
    
}


/**
 * An implementation of price_api that uses the index-api.bitcoin.com oracle
 */
class price_api_bitcoin_com {
    
    public function retrieve_price_history( $currency ) {
        $url = 'https://index-api.bitcoin.com/api/v0/history?span=all&unix=1';
        mylogger()->log( "Retrieving $currency price history from $url", mylogger::info );
        $buf = file_get_contents( $url );
        
        return $this->transform_to_csv($buf);
    }

    /**
     * retrieves the avg price for currency during the past 24 hours.
     */
    public function get_24_hour_avg_price( $currency ) {
        
        // this API provider supplies spot price only, not 24hr avg.        
        return $this->get_spot_price($currency);
        
    }
    
    private function transform_to_csv($buf) {
        // transform JSON
        // [
        //    [
        //      1552176000,
        //      393251
        //    ],
        //    ...
        // ]
        
        // to CSV
        //   DateTime,High,Low,Average,Volume BTC
        //   2017-05-02 00:00:00,,,1441.09,
        
        $json = json_decode($buf);
        if(@count($json) < 10) {
            throw new Exception("Invalid response from api provider");
        }
        $csv = [];
        $csv[] = implode(',', ['DateTime','High','Low','Average','Volume BTC'] );
        foreach($json as $row) {
            echo "added row\n";
            $csv[] = implode(',', [date('c', $row[0]), null, null, $row[1] / 100, null] );
        }
        return implode("\n", $csv);
    }
    
    
    private function get_spot_price( $currency ) {
        $url_mask = 'https://index-api.bitcoin.com/api/v0/price/%s';
        $url = sprintf( $url_mask, strtolower($currency) );
        mylogger()->log( "Retrieving $currency price history from $url", mylogger::info );
        $buf = file_get_contents( $url );
        $data = json_decode( $buf, true );
        
        return $data['price'];
    }
}

