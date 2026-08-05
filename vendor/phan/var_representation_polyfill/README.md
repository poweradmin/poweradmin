var_representation_polyfill
=============================

[var_representation_polyfill](https://github.com/phan/var_representation_polyfill) is a polyfill for https://pecl.php.net/var_representation

This provides a polyfill for the function `var_representation(mixed $value, int $flags = 0): string`, which converts a
variable to a string in a way that fixes the shortcomings of `var_export()`

See [the var_representation PECL documentation](https://github.com/TysonAndre/var_representation) for more details

Forked from https://github.com/TysonAndre/var_representation_polyfill for PHP 8.5 support.

Installation
------------

```
composer require phan/var_representation_polyfill
```

Usage
-----

```php
// uses short arrays, and omits array keys if array_is_list() would be true
php > echo var_representation(['a','b']);
[
  'a',
  'b',
]

// can dump everything on one line.
php > echo var_representation(['a', 'b', 'c'], VAR_REPRESENTATION_SINGLE_LINE);
['a', 'b', 'c']

php > echo var_representation("uses double quotes: \$\"'\\\n");
"uses double quotes: \$\"'\\\n"

// Can disable the escaping of control characters as of polyfill version 0.1.0
// (e.g. if the return value needs to be escaped again)
php > echo var_representation("has\nnewlines", VAR_REPRESENTATION_UNESCAPED);
'has
newlines'
php > echo json_encode("uses single quotes:\0\r\n\$\"'\\");
"uses single quotes:\u0000\r\n$\"'\\"
php > echo json_encode(var_representation("uses single quotes:\0\r\n\$\"'\\",
                                          VAR_REPRESENTATION_UNESCAPED));
"'uses single quotes:\u0000\r\n$\"\\'\\\\'"
```
