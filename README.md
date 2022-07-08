## Migration scanner for MySQL 8.0 / MariaDB 10.5 compatibility

Use alongside Jenkins scan (CMS 21 / XFP 8.2 upgrades), for an easy overview of potential issues with reserved words

## Usage:
```
php scan.php /path/to/customer/repo
```
Returns a list of all migrations with their usage of reserved words, including line number
