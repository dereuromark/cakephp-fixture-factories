<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpFixtureFactories\Test\Factory;

use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Class AuthorFactory
 *
 * @extends BaseFactory<\TestApp\Model\Entity\Author>
 */
class AuthorFactory extends BaseFactory
{
    public const JSON_FIELD_DEFAULT_VALUE = [
        'subField1' => 'subFieldValue1',
        'subField2' => 'subFieldValue2',
    ];

    protected array $skippedSetters = [
        'field_with_setter_1',
    ];

    protected function getRootTableRegistryName(): string
    {
        return 'Authors';
    }

    public function definition(GeneratorInterface $generator): array
    {
        return [
            'name' => $generator->name(),
            'field_with_setter_1' => $generator->word(),
            'field_with_setter_2' => $generator->word(),
            'field_with_setter_3' => $generator->word(),
            'json_field' => self::JSON_FIELD_DEFAULT_VALUE,
        ];
    }

    protected function configure(): static
    {
        return $this->forAddress();
    }

    public function hasArticles(mixed $n = 1, mixed $parameter = null): self
    {
        if (!is_int($n)) {
            $originalParameter = $n;
            $n = is_int($parameter) ? $parameter : 1;
            $parameter = $originalParameter;
        }

        return $this->has(ArticleFactory::new($parameter)->count($n)->without('Authors'));
    }

    public function withArticles(mixed $parameter = null, int $n = 1): self
    {
        if (is_int($parameter) && $n === 1) {
            return $this->hasArticles($parameter);
        }

        return $this->hasArticles($n, $parameter);
    }

    public function forAddress(mixed $parameter = null): self
    {
        return $this->with('Address', AddressFactory::new($parameter));
    }

    public function withAddress(mixed $parameter = null): self
    {
        return $this->forAddress($parameter);
    }

    public function fromCountry(string $name): self
    {
        return $this->with('Address.City.Countries', CountryFactory::new(compact('name')));
    }
}
