<?php

class t_matrix extends test_base {

    public function runtests() {
        
        $this->alltime();
    }
    
    protected function alltime() {
        $args = "-g  --addresses=18AKtp28CmgiZvBjJLpDimUFZKMzHfK6ss --report-type=matrix --cost-method=fifo --date-start=2015-05-01 --date-end=2015-06-30";
        $data = bitpricescmd::runjson( $args );
        
        $numrows = count($data);
        $lastrow = $data[count($data)-1];

        $this->eq( $numrows, 26, "Number of rows in report" );

        $col = 'Date Purchased';
        $this->eq( $lastrow[$col], '2015-06-06', $col );
        
        $col = 'Original Amount';
        $this->eq( $lastrow[$col], 0.43492400, $col );

        $col = 'Amount Sold';
        $this->eq( $lastrow[$col], 0.43492400, $col );

        $col = 'Cost Basis Price';
        $this->eq( $lastrow[$col], 226.89, $col );

        $col = 'Total Cost Basis';
        $this->eq( $lastrow[$col], 98.68, $col );

        $col = 'Date Sold';
        $this->eq( $lastrow[$col], '2015-06-06', $col );

        $col = 'Sale Value Price';
        $this->eq( $lastrow[$col], 226.89, $col );

        $col = 'Total Sale Value';
        $this->eq( $lastrow[$col], 98.68, $col );
        
        $col = 'Realized Gain';
        $this->eq( $lastrow[$col], 0.00, $col );

        $col = 'Short/Long';
        $this->eq( $lastrow[$col], 'Short', $col );
    }

}