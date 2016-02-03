<?php

class t_avgperiodic extends test_base {

    public function runtests() {
        
        $this->alltime();
        $this->month();
    }
    
    protected function alltime() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --cols=gainlossavgperiodic --date-start=2015-05-01 --date-end=2015-06-30";
        $data = bitpricescmd::runjson( $args );
        
        $lastrow = $data[count($data)-1];
        
        $col = 'Realized Gain (AvCost Periodic)';
        $this->eq( $lastrow[$col], -5.46, $col );

    }

    protected function month() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --cols=gainlossavgperiodic --date-start=2015-05-01 --date-end=2015-06-01";
        $data = bitpricescmd::runjson( $args );
        
        $lastrow = $data[count($data)-1];

        // discrepancy:  libratax shows this value as -0.62.
        $col = 'Realized Gain (AvCost Periodic)';
        $this->eq( $lastrow[$col], -0.62, $col );

    }
}