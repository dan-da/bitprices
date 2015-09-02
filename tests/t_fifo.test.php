<?php

class t_fifo extends test_base {

    public function runtests() {
        
        $this->alltime();
        $this->month();
    }
    
    protected function alltime() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --cols=gainlossfifo";
        $data = bitpricescmd::runjson( $args );
        
        $lastrow = $data[count($data)-1];
        
        $col = 'Realized Gain (FIFO, Short)';
        $this->eq( $lastrow[$col], -5.52, $col );

        $col = 'Realized Gain (FIFO, Long)';
        $this->eq( $lastrow[$col], 0.00, $col );
    }

    protected function month() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --cols=gainlossfifo --date-start=2015-05-01 --date-end=2015-06-01";
        $data = bitpricescmd::runjson( $args );
        
        $lastrow = $data[count($data)-1];
        
        $col = 'Realized Gain (FIFO, Short)';
        $this->eq( $lastrow[$col], -5.44, $col );

        $col = 'Realized Gain (FIFO, Long)';
        $this->eq( $lastrow[$col], 0.00, $col );
    }
}