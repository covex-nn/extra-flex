Extra-Flex for Symfony
======================

`Extra-Flex` is a composer plugin for [Symfony Flex][1].

`Extra-Flex` allows to keep recipe for composer package together in the same repository
with a package itself.
 
`Extra-Flex` allows to install recipe on `require` command and uninstall on `remove` command.
Also recipe could be applied on demand without requiring a package with additional `apply` command.

To enable `Extra-Flex` run `composer require covex-nn/extra-flex` after `composer create-project symfony/skeleton`

To include recipe into a package, add extra data to `composer.json`:

```json
{
    "extra": {
        "recipe-dir": ".flex"     
    }
}
```

Example
-------

Require `covex-nn/extra-flex-foobar` and apply recipe immediately:

```
composer create-project symfony/skeleton .
composer require covex-nn/extra-flex
composer require covex-nn/extra-flex-foobar
composer remove covex-nn/extra-flex-foobar
```

Apply recipe from `covex-nn/extra-flex-foobar` without require package:

```
composer create-project symfony/skeleton .
composer require covex-nn/extra-flex
composer apply covex-nn/extra-flex-foobar 1.0.2
cat composer.json
```

See [`composer.json`][2] from [`covex-nn/extra-flex-foobar`][2] for details.

Extending Flex
--------------

To extend Flex, your composer-plugin could subscribe to one of Extra-Flex events:

* `pre-flex-configurator-install`
* `post-flex-configurator-install`
* `pre-flex-configurator-unconfigure`
* `post-flex-configurator-unconfigure`
* `pre-flex-downloader-getRecipes`
* `post-flex-downloader-getRecipes`

[1]: https://github.com/symfony/flex
[2]: https://github.com/covex-nn/extra-flex-foobar/blob/master/composer.json
