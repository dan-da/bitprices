# bitprices : a bitcoin wallet auditing tool.

A command-line tool that generates transaction reports with the USD (fiat)
value on the date of each transaction.  As well as FIFO/LIFO disposal reports.

Let's see a couple examples, shall we?

# Example Price History Report

./bitprices.php --addresses=1M8s2S5bgAzSSzVTeL7zruvMPLvzSkEAuv -g
```
+------------+------------+------------------+-----------+-------------+----------------+---------------+
| Date       | Addr Short | BTC Amount       | USD Price | USD Amount  | USD Amount Now | USD Gain      |
+------------+------------+------------------+-----------+-------------+----------------+---------------+
| 2011-11-16 | 1M8..Auv   |  500000.00000000 |      2.46 |  1230000.00 |   188355000.00 |  187125000.00 |
| 2011-11-16 | 1M8..Auv   | -500000.00000000 |      2.46 | -1230000.00 |  -188355000.00 | -187125000.00 |
| 2013-11-26 | 1M8..Auv   |       0.00011000 |    913.95 |        0.10 |           0.04 |         -0.06 |
| 2013-11-26 | 1M8..Auv   |      -0.00011000 |    913.95 |       -0.10 |          -0.04 |          0.06 |
| 2014-11-21 | 1M8..Auv   |       0.00010000 |    351.95 |        0.04 |           0.04 |          0.00 |
| 2014-12-09 | 1M8..Auv   |       0.00889387 |    353.67 |        3.15 |           3.35 |          0.20 |
| 2015-06-05 | 1M8..Auv   |       0.44520000 |    226.01 |      100.62 |         167.71 |         67.09 |
| 2015-06-07 | 1M8..Auv   |       0.44917576 |    226.02 |      101.52 |         169.21 |         67.69 |
| 2015-10-17 | 1M8..Auv   |       0.00010000 |    270.17 |        0.03 |           0.04 |          0.01 |
| 2015-11-05 | 1M8..Auv   |       0.00010000 |    400.78 |        0.04 |           0.04 |          0.00 |
| Totals:    |            |       0.90356963 |           |      205.40 |         340.39 |        134.99 |
+------------+------------+------------------+-----------+-------------+----------------+---------------+
```

note: This address was chosen for the example because it is a well known address
listed on theopenledger.com as having the largest transaction ever. Also, the
number of transactions is small, making it a good size for an example.

http://www.theopenledger.com/9-most-famous-bitcoin-addresses/

# Example Disposal Report

This is a disposal report for the same address as above. Default columns and
cost method (FIFO) are used.

./bitprices.php --addresses=1M8s2S5bgAzSSzVTeL7zruvMPLvzSkEAuv --report-type=schedule_d -g

```
+--------------------------+---------------+--------------------+------------+------------+-----------+-----------------+
| Description              | Date Acquired | Date Sold/Disposed | Proceeds   | Cost Basis | Gain/Loss | Short/Long-term |
+--------------------------+---------------+--------------------+------------+------------+-----------+-----------------+
| 500000.00000000 Bitcoins | 2011-11-16    | 2011-11-16         | 1230000.00 | 1230000.00 |      0.00 | Short           |
| 0.00011000 Bitcoins      | 2013-11-26    | 2013-11-26         |       0.10 |       0.10 |      0.00 | Short           |
|                          |               | Net Summary Long:  |       0.00 |       0.00 |      0.00 |                 |
|                          |               | Net Summary Short: | 1230000.10 | 1230000.10 |      0.00 |                 |
+--------------------------+---------------+--------------------+------------+------------+-----------+-----------------+
```

# About bitprices

This tool reports price history for all transactions associated with one or more
bitcoin addresses for an arbitrary range of dates.

Such information can be useful for understanding present day gain/loss or when
determining cost basis for tax reporting or other purposes.

The tool is also useful for auditing because it can be used by a third party
without direct access to your wallet.

As of version 1.0.0, the tool can also generate a sales/disposal report with
realized gains, in schedule D format.

If all addresses from a given wallet are provided (including change addresses)
then the tool can provide a full and complete report of all wallet transactions.

Daily exchange rates are obtained from bitcoinaverage.com.  All fiat currencies
supported by bitcoinaverage.com may be reported, not only USD.

Historic transaction data for each address is obtained from a blockchain API
service provider, which can be either a third party service or something you
run locally.

At present, the supported blockchain APIs are:
* toshi:  online service at toshi.io, or run locally.
* insight: online service at insight.bitpay.com, or run locally.
* btcd: typically this is run locally.

Any public address or set of addresses may be reported on.

# Use at your own risk.

The author makes no claims or guarantees of correctness.  This software
has not been reviewed or certified by a CPA.

The schedule D report is provided for informational purposes only and may not
be accurate or applicable to your situation.  You should NOT present these
results to tax authorities.  Instead, consult with a tax professional.


# Limitations

* This tool assumes that all incoming BTC are purchases and all outgoing are
  sales. This may or may not fit your accounting needs.
* This tool does not presently exclude intra-wallet transfers. So movements to
  change addresses appear in the reports which is not always desirable.
  
These limitations may be lifted in the future.  Please let the author
know if either of these are important to you.

note: [libratax](http://libratax.com) is a service that allows
manual control of payment type for each transfer if you need that feature.

# Pricing Granularity

Exchange rates are based on the average *Daily* rate from bitcoinaverage.com.
Therefore, the exact price at the moment of the transaction is not reflected.

For transactions that occurred "Today", the latest 24 hour average value from
bitcoinaverage.com is used.


# A note about Fiat Columns

The columns "USD Price" and "USD Amount" are based on the historic price at the
time of each transaction.  So these amounts are typically very different than
they would be if each transaction were valued at today's exchange rate.

The USD Amount total provides a picture of net income valued in dollars at
the time.

note: The actual column names will vary by currency, eg "EUR Balance" for Euros.


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
    
    --addresses=<csv>    comma separated list of bitcoin addresses
    --addressfile=<path> file containing bitcoin addresses, one per line.
    --txfile=<path>      file containing transactions in libratax csv format.
    
                         note: addresses, addressfile and txfile are exclusive.
    
    --api=<api>          toshi|btcd|insight.   default = toshi.
    
    --direction=<dir>    transactions in | out | both   default = both.
    --summarize-tx=<b>   yes|no  default = yes
                           Use one row per each tx, even when change address(es)
    
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
    

# Auditing by a Third Party

bitprices facilitates third party auditing because it provides a complete
transaction history without requiring any private keys.

If the auditor is using bitprices, they will need all the wallet addresses.

For modern HD wallets, the auditor would simply ask for the wallet master XPub
key. The auditor would then use a tool such as [hd-wallet-addrs](https://github.com/dan-da/hd-wallet-addrs)
to obtain the complete address list.


# Exporting addresses from wallets.

## HD-Wallets

The recommended approach for exporting used addresses from an HD wallet is to use
the [hd-wallet-addrs](https://github.com/dan-da/hd-wallet-addrs) tool.

A web interface for the tool [is available](http://mybitprices.dev/hd-wallet-addrs.html).

## Non HD-Wallets

There is no standard mechanism for exporting wallet addresses, and some wallets
make it difficult or near impossible.

The key thing to keep in mind is that in order for the report generated by this
tool to match up with your wallet's transaction history and balance, you must
provide *all* wallet addresses that have received or spent funds --
including change addresses.

### Bitcoin Core.

Exporting addresses from Bitcoin Core is relatively simple on linux or a mac.

```
bitcoin-cli listaddressgroupings | grep '",'
```

See:
* http://bitcoin.stackexchange.com/questions/16913/where-can-i-see-my-bitcoin-addresses
* http://bitcoin.stackexchange.com/questions/9077/how-to-get-all-addresses-including-the-change-addresses-from-bitcoind

### Other wallets.

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

# Blockchain API provider notes.

tip!  use the --api flag to switch between blockchain API providers.

Each API has strengths and weaknesses. Some are faster than others,
or easier/harder to run locally. For first time usage, the online toshi
service is recommended, and it is the default.

At present, running the forked version of btcd locally seems to be the best
option for fastest report generation.

## Toshi

as of v0.1.0

* Fast for transactions with few inputs/outputs
* does not support filtering unrelated inputs/outputs.   takes minutes and
  generates huge results.
* when running locally, it is very slow to sync blockchain (months) unless the
database is stored on SSD drive.
* does not support querying multiple addresses at once.

### Toshi Fork

This tool calls a toshi (http://toshi.io) API to list all the transactions
for each address.  Toshi's API is slow for two reasons:

* Toshi returns all inputs and outputs for each transaction although we need
only those for the address we are interested in.  I have seen result sets over
20 megabytes for a single address.
* The API supports only one address at a time.  It would be faster to query
for all addresses at once.

I have created a toshi fork at http://github.com/dan-da/toshi that implements
a new API (/addresses/.../transactionsfiltered) which filters out transaction
inputs/outputs we are not interested in.

The savings are significant.  An API call that was taking 6 seconds and
returning a huge result set now takes .2 seconds and returns less than 1k of
data.

Further optimizations are possible:
* the new API is still processing only one address at a time.
* the new API retrieves extra metadata about the address that is unnecessary.


## Insight

as of v0.2.18

* Fast enough for transactions with few inputs/outputs, but 2-3x slower than
  toshi in my testing for larger transactions.
* does not support filtering unrelated inputs/outputs.  takes (more) minutes and
  generates huge results.
* supports querying multiple addresses at once with multiaddr API, but includes
  each TX only once which presently confuses bitprices, leading to invalid
  balances.

## btcd

as of btcd v0.12.0-beta

* my patch was accepted!  it quickly generates efficient, filtered results.
* no public online API service available that I'm aware of, must be run locally.
* Very fast transaction lookups, even with many inputs/outputs.
* must be run with addrindex=1 option.  ( important! )

### Historical note:

Originally btcd did not include addresses and amount in inputs. I added this
functionality as well as a filtering optimization in a forked version, and
submitted pull requests.  Mainline has now accepted both changes.

See:
* https://github.com/btcsuite/btcd/pull/487
* https://github.com/btcsuite/btcd/pull/516


# LibraTax import mode

bitprices now supports reading in transactions from a LibraTax transaction
report rather than querying the blockchain directly.

In this mode, all data including price history is obtained from the LibraTax
input file.  No network requests are made.

This can be useful for two purposes:

 1. Verifying bitprices output against LibraTax, and vice-versa.
 2. Generating some reports that LibraTax does not presently offer.
 
See the --txfile flag for usage.

note: One limitation of this mode is that LibraTax reports do not include the
address for all transfers, so the addr field will contain some invalid
addresses.


# Todos

* implement avg-cost cost-method
* implement unrealized gain
* interpret insight's multiaddr results correctly. In theory it should be faster.
* hopefully get toshi changes accepted by toshi project maintainers.
* ~~Create website frontend for the tool.~~ done. see http://mybitprices.info
* ~~Add Bip32, 39, 44 support ( HD Wallets ) so it is only necessary to
  input master public key and entire wallet can be scanned.~~ done. see hd-wallet-addrs
* ~~optimize btcd further ( filter inputs/outputs )~~ done.
* ~~hopefully get btcd changes accepted by btcd project maintainers.~~ done.
* ~~LIFO cost-method support.  verify same results as libratax.~~ done.

