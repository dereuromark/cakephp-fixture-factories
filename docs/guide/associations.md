# Associations

If your application is not using CakePHP, or if you want to add
associations within your factories, please take a look at the [Associations for non-CakePHP apps](non-cakephp-associations) section
in order to define the associations of your tables. After defining your associations, you may
continue with the documentation below.

## The association API
If you have baked your factories with the option `-m` or `--methods`, you will have noticed that a method for each association
has been inserted in the factories. These directional helpers make the cardinality explicit at the call site. For example, we can
create an article with 10 authors as follows:
```php
$article = ArticleFactory::new()->with('Authors', AuthorFactory::new()->count(10))->save();
```
or using the method defined in our `ArticleFactory`:
```php
$article = ArticleFactory::new()->hasAuthors(10)->save();
```

If we wish to randomly populate the field `biography` of the 10 authors of our article, with 10 different biographies:
```php
$article = ArticleFactory::new()->hasAuthors(10, function (AuthorFactory $factory, GeneratorInterface $generator) {
    return [
        'biography' => $generator->realText(),
    ];
})->save();
```
It is also possible to use the _dot_ notation to create associated fixtures:
```php
$article = ArticleFactory::new()->with('Authors.Address.City.Country', ['name' => 'Kenya'])->save();
```
will create an article, with an author having itself an address in Kenya.

The second parameter of `with()` can be:
* an array of fields and their values
* a string (or an array of strings), which will be assigned to the table's display field
* an integer: the number of associated entities created
* a factory

Ultimately, the square bracket notation provides a mean to specify the number of associated
data created:
```php
$article = ArticleFactory::new()->count(5)->with('Authors[3].Address.City.Country', ['name' => 'Kenya'])->saveMany();
```
will create 5 articles, having themselves each 3 different associated authors, all located in Kenya.

It is also possible to specify the fields of a toMany associated model.
For example, if we wish to create a random country with two cities having known names:

```php
$country = CountryFactory::new()->with('Cities', [
    ['name' => 'Nairobi'],
    ['name' => 'Mombasa'],
])->save();
```

This can be useful if your business logic uses hard coded values, or constants.

Note that when an association has the same name as a virtual field,
the virtual field will overwrite the data prepared by the associated factory.

Similarly to `new()`, it is possible to inject a string into an associated factory:
```php
$country = CountryFactory::new()->with('Cities', 'Nairobi')->save();
```
or
```php
$country = CountryFactory::new()->with('Cities', ['Nairobi', 'Mombasa'])->save();
```

## Factory injection

When building associations, you may simply provide a factory as parameter. Example:

```php
$country = CountryFactory::new()->with('Cities', CityFactory::new()->threeCitiesAndFiveVillages())->save();
```
will provide a country associated with three cities and five villages.

## Entity injection

You may also inject an existing entity. The previous example would be now:
```php
$threeCitiesAndFiveVillages = CityFactory::new()->threeCitiesAndFiveVillages()->buildMany();
$country = CountryFactory::new()->with('Cities', $threeCitiesAndFiveVillages)->save();
```

You may also pass an array of factories:
```php
$threeCitiesAndFiveVillages = CityFactory::new()->threeCitiesAndFiveVillages()->buildMany();
$country = CountryFactory::new()->with('Cities', [
    CityFactory::new()->threeCitiesAndFiveVillages(),
    CityFactory::new()->capitalCity(),
])->save();
```
