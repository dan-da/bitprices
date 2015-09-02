<?php

class t_8949_fifo extends test_base {

    public function runtests() {
        
        $this->alltime();
        $this->month();
    }
    
    protected function alltime() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --report-type=schedule_d --cost-method=fifo --date-start=2015-05-01 --date-end=2015-06-30";
        $data = bitpricescmd::runjson( $args );
        
        $numrows = count($data);
        $lastrow = $data[count($data)-1];
        $prevlastrow = $data[count($data)-2];
        $prevprevlastrow = $data[count($data)-3];

        $this->eq( $numrows, 28, "Number of rows in report" );        
        
        $col = 'Proceeds';
        $this->eq( $lastrow[$col], 604.97, "Net Summary Short Proceeds" );

        $col = 'Cost Basis';
        $this->eq( $lastrow[$col], 610.49, "Net Summary Short Cost Basis" );

        $col = 'Gain/Loss';
        $this->eq( $lastrow[$col], -5.52, "Net Summary Short Gain/Loss" );

        $col = 'Proceeds';
        $this->eq( $prevlastrow[$col], 0.00, "Net Summary Long Proceeds" );

        $col = 'Cost Basis';
        $this->eq( $prevlastrow[$col], 0.00, "Net Summary Long Cost Basis" );

        $col = 'Gain/Loss';
        $this->eq( $prevlastrow[$col], 0.00, "Net Summary Long Gain/Loss" );
        
        
        $col = 'Proceeds';
        $this->eq( $prevprevlastrow[$col], 98.68, 'Proceeds' );        

        $col = 'Cost Basis';
        $this->eq( $prevprevlastrow[$col], 98.68, 'Cost Basis' );

    }

    protected function month() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --report-type=schedule_d --cost-method=fifo --date-start=2015-05-01 --date-end=2015-06-01";
        $data = bitpricescmd::runjson( $args );

        $numrows = count($data);
        $lastrow = $data[count($data)-1];
        $prevlastrow = $data[count($data)-2];
        $prevprevlastrow = $data[count($data)-3];
        
        $this->eq( $numrows, 26, "Number of rows in report" );        

        $col = 'Proceeds';
        $this->eq( $lastrow[$col], 504.72, "Net Summary Short Proceeds" );

        $col = 'Cost Basis';
        $this->eq( $lastrow[$col], 510.16, "Net Summary Short Cost Basis" );

        $col = 'Gain/Loss';
        $this->eq( $lastrow[$col], -5.44, "Net Summary Short Gain/Loss" );

        $col = 'Proceeds';
        $this->eq( $prevlastrow[$col], 0.00, "Net Summary Long Proceeds" );

        $col = 'Cost Basis';
        $this->eq( $prevlastrow[$col], 0.00, "Net Summary Long Cost Basis" );

        $col = 'Gain/Loss';
        $this->eq( $prevlastrow[$col], 0.00, "Net Summary Long Gain/Loss" );
        
        
        $col = 'Proceeds';
        $this->eq( $prevprevlastrow[$col], 151.83, 'Proceeds' );        
 
        $col = 'Cost Basis';
        $this->eq( $prevprevlastrow[$col], 152.03, 'Cost Basis' );
   }
}