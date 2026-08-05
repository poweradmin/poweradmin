## Detect disallowed calls without any other PHPStan rules

If you want to use this PHPStan extension without running any other PHPStan rules, you can use `phpstan.neon` config file that looks like this (the `customRulesetUsed: true` and the missing `level` key are the important bits):

```neon
parameters:
    customRulesetUsed: true
includes:
    - vendor/spaze/phpstan-disallowed-calls/extension.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-dangerous-calls.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-execution-calls.neon
```
