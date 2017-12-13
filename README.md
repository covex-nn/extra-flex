Extra-Flex composer plugin
==========================

`Extra-Flex` is a composer plugin for [Symfony Flex](https://github.com/symfony/flex).

`Extra-Flex` allows to keep recipe for composer package together in the same repository
with a package itself.
 
`Extra-Flex` allows to install recipe on `require` command and uninstall on `remove` command.
Also recipe could be applied on demand without requiring a package with additional `apply` command.

To enable `Extra-Flex` run `composer require covex-nn/extra-flex dev-master`

To include recipe into a package, add extra data to `composer.json`:

```json
{
    "extra": {
        "recipe-dir": ".flex"     
    }
}
```   

See [`covex-nn/extra-flex-foobar`](https://github.com/covex-nn/extra-flex-foobar/blob/master/composer.json) for example
