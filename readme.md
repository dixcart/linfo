# Linfo fork with added Pingdom support

__IMPORTANT__: Please see /trunk/ folder for original readme and license

## About

Adds basic support for Pingdom's custom http check to monitor things other than response time and uptime.  At present supports cpu load and ram usage.

Future versions will allow configurable levels for a check to be "not OK" and more metrics.

## Usage

Add the "out=pingdom" and "param" querystring parameters to your linfo URL: e.g.:

http://www.example.com/linfo/index.php?out=pingdom&param=ram-used

Available param values are:

* __load__: Cpu Load (%)
* __swap-used__: Amount in MB of swap/page file usage
* __ram-used__: Amount in MB of RAM usage