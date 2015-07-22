# bitprices

A command-line tool that generates a report of transactions with the USD (fiat)
value on the date of each transaction.

Hopefully one day soon all wallet software will include reporting that includes
the exchange rate at the time of each transaction.   Until that time, this
tool may fill a gap.  Also, it can be used for addresses outside of your own
wallet.

This tool is useful for determining USD values for all transactions associated
with one or more bitcoin addresses for an arbitrary range of dates. Such
information can be useful when determining cost basis for tax reporting or other
purposes.

If all addresses from a given wallet are provided (including change addresses)
then the tool can provide a full and complete report of all wallet transactions.

Daily exchange rates are obtained from bitcoinaverage.com.  All fiat currencies
supported by bitcoinaverage.com may be reported, not only USD.

Historic transaction data for each address is obtained from toshi.io, a service
of Coinbase.com that provides a free API for querying blockchain data.

Any public address or set of addresses may be reported on.

# Use at your own risk.

The author makes no claims or guarantees of correctness.

# Pricing Granularity

Exchange rates are based on the average *Daily* rate from bitcoinaverage.com.
Therefore, the exact price at the moment of the transaction is not reflected.

For transactions that occurred "Today", the latest 24 hour average value from
bitcoinaverage.com is used.

# Example Report

This report was obtained with the following command, using default columns:

./bitprices.php --addresses=1M8s2S5bgAzSSzVTeL7zruvMPLvzSkEAuv --outfile=/tmp/report.txt -g
```
+------------+----------+------------+-----------------+-----------------+-----------------+------------+------------+-------------+-----------+
| Date       | Time     | Addr Short | BTC In          | BTC Out         | BTC Balance     | USD In     | USD Out    | USD Balance | USD Price |
+------------+----------+------------+-----------------+-----------------+-----------------+------------+------------+-------------+-----------+
| 2011-11-16 | 05:59:08 | 1M8..Auv   | 500000.00000000 |      0.00000000 | 500000.00000000 | 1230000.00 |       0.00 |  1230000.00 |      2.46 |
| 2011-11-16 | 09:17:36 | 1M8..Auv   |      0.00000000 | 500000.00000000 |      0.00000000 |       0.00 | 1230000.00 |        0.00 |      2.46 |
| 2013-11-26 | 16:36:25 | 1M8..Auv   |      0.00011000 |      0.00000000 |      0.00011000 |       0.10 |       0.00 |        0.10 |    913.95 |
| 2013-11-26 | 17:37:51 | 1M8..Auv   |      0.00000000 |      0.00011000 |      0.00000000 |       0.00 |       0.10 |        0.00 |    913.95 |
| 2014-11-21 | 22:24:05 | 1M8..Auv   |      0.00010000 |      0.00000000 |      0.00010000 |       0.04 |       0.00 |        0.04 |    351.95 |
| 2014-12-09 | 17:55:09 | 1M8..Auv   |      0.00889387 |      0.00000000 |      0.00899387 |       3.15 |       0.00 |        3.18 |    353.67 |
| 2015-06-05 | 15:05:14 | 1M8..Auv   |      0.44520000 |      0.00000000 |      0.45419387 |     100.62 |       0.00 |      103.80 |    226.01 |
| 2015-06-07 | 18:31:53 | 1M8..Auv   |      0.44917576 |      0.00000000 |      0.90336963 |     101.52 |       0.00 |      205.32 |    226.02 |
+------------+----------+------------+-----------------+-----------------+-----------------+------------+------------+-------------+-----------+
```

note: This address was chosen for the example because it is a well known address
listed on theopenledger.com as having the largest transaction ever. Also, the
number of transactions is small, making it a good size for an example.

http://www.theopenledger.com/9-most-famous-bitcoin-addresses/

# Output formats

The report may be printed in the following formats:
* plain  - an ascii formatted table, as above.  intended for humans.
* csv - CSV format.  For spreadsheet programs.
* json - raw json format.  for programs to read easily.
* jsonpretty - pretty json format.  for programs or humans.

Additionally, the report may contain incoming transactions only, outgoing
transactions only, or both types.

# Usage

   bitprices.php

   This script generates a report of transactions with the USD value
   at the time of each transaction.

   Options:

    -g                   go!
    
    --addresses          comma separated list of bitcoin addresses
    --addressfile        file containing bitcoin addresses, one per line.
    
    --direction          transactions in | out | both   default = both.
    
    --date_start=<date>  Look for transactions since date. default = all.
    --date_end=<date>    Look for transactions until date. default = now.
    
    --currency=<curr>    symbol supported by bitcoinaverage.com.  default = USD.
    
    --cols=<cols>        default=date,time,addrshort,btcin,btcout,btcbalance
                                 fiatin,fiatout,fiatprice
                         others=address,tx,txshort
                                btcbalanceperiod,fiatbalanceperiod
                                
    --outfile=<file>     specify output file path.
    --format=<format>    plain|csv|json|jsonpretty     default=plain


# Exporting addresses from wallets.

Every bitcoin wallet has its own method for exporting addresses.  Some make it
easy, some require digging into hidden files, or manually copying and pasting.

The key thing to keep in mind is that in order for the report generated by this
tool to match up with your wallet's transaction history and balance, you must
provide *all* wallet addresses that have received or spent funds --
including change addresses.

## Bitcoin Core.

Exporting addresses from Bitcoin Core is relatively simple.

```
bitcoin-cli listaddressgroupings | grep '",'
```

See:
* http://bitcoin.stackexchange.com/questions/16913/where-can-i-see-my-bitcoin-addresses
* http://bitcoin.stackexchange.com/questions/9077/how-to-get-all-addresses-including-the-change-addresses-from-bitcoind

## Other wallets.

Please add instructions here.  ( pull request )


# Requirements

PHP command-line interpreter installed in your path.  version 5.5.9 and above.

may work with lower versions.

# Installation and Running.

## Unix/mac
```
 git clone or download/unzip file.
 chmod +x bitprices.php.  ( if necessary )
 ./bitprices.php
```
## Windows
```
 git clone or download/unzip file.
 php ./bitprices.php
```

# Todos

* Add Bip32, 39, 44 support ( HD Wallets ) so it is only necessary to
  input master public key and entire wallet can be scanned.
* Create website frontend for the tool.
