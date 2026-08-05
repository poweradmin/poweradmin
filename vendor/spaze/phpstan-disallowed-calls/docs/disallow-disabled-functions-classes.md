### Disallow disabled functions & classes

Run `bin/generate-from-disabled.php` to generate a configuration based on the `disable_functions` & `disable_classes` PHP options. The configuration will be dumped to STDOUT in NEON format, you can save it to a file and include it in your PHPStan configuration.

The file needs to be pre-generated because different environments often have different PHP configurations and if disabled functions & classes would be disallowed dynamically, the configuration would vary when executed for example in dev & CI environments.
