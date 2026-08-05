# Keep Cognitive Complexity Down

<br>

Cognitive complexity tells us, how difficult code is to understand by a reader.

**How is cognitive complexity measured?**

```php
function get_words_from_number(int $number): string
{
    $amountInWords = '';

    if ($number === 1) {            // + 1
        $amountInWords = 'one';
    } elseif ($number === 2) {      // + 1
        $amountInWords = 'couple';
    } elseif ($number === 3) {      // + 1
        $amountInWords = 'a few';
    } else {                        // + 1
        $amountInWords = 'a lot';
    }

    return $amountInWords;
}
```

This function uses nesting, conditions and continue back and forth. It's hard to read and results in **cognitive complexity of 4**.

How to keep **cognitive complexity on 1**? Read [Cognitive load is what matters](https://minds.md/zakirullin/cognitive) or [Sonar paper about cognitive complexity metrics](https://www.sonarsource.com/docs/CognitiveComplexity.pdf) that inspired this repository.

<img src="https://github.com/zakirullin/cognitive-load/blob/main/img/cognitiveloadv5paper.png?raw=true">


<br>

## Install

```bash
composer require tomasvotruba/cognitive-complexity --dev
```

The package is available on PHP 7.4+.

<br>

## Usage

With [PHPStan extension installer](https://github.com/phpstan/extension-installer), everything is ready to run.

Enable each item on their own with simple configuration:

```yaml
# phpstan.neon
parameters:
    cognitive_complexity:
        class: 50
        function: 8
```

<br>

## Detect complex Class Dependency Trees

In classes like controllers, Rector rules, PHPStan rules or other services of specific type, the complexity can be hidden in the __construct() dependencies. Simple class with 10 dependencies is more complex than complex class with 2 dependencies.

That's why there is a rule to detect these dependency trees. It checks:

* complexity of **current class**
* **constructor dependencies and their class complexity** together

Final number is compared and used as a final complexity:

```yaml
# phpstan.neon
parameters:
    cognitive_complexity:
        dependency_tree: 150
        dependency_tree_types:
            # only these explicit types are checked, nothing else
            - Rector\Contract\Rector\RectorInterface
```

<br>

Happy coding!
