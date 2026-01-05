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

use Cake\Datasource\EntityInterface;
use CakephpFixtureFactories\Factory\BaseFactory;
use CakephpFixtureFactories\Generator\GeneratorInterface;

/**
 * Class AuthorFactory
 * @method \TestApp\Model\Entity\Author getEntity()
 * @method \TestApp\Model\Entity\Author[] getEntities()
 * @method \TestApp\Model\Entity\Author|\TestApp\Model\Entity\Author[] persist()
 * @method static \TestApp\Model\Entity\Author get(mixed $primaryKey, array $options = [])
 * @method static \TestApp\Model\Entity\Author firstOrFail($conditions = null)
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

    protected function setDefaultTemplate(): void
    {
        $this
            ->setDefaultData(function (GeneratorInterface $generator) {
                return [
                    'name' => $generator->name(),
                    'field_with_setter_1' => $generator->word(),
                    'field_with_setter_2' => $generator->word(),
                    'field_with_setter_3' => $generator->word(),
                    'json_field' => self::JSON_FIELD_DEFAULT_VALUE,
                ];
            })
            ->withAddress();
    }

    public function withArticles(mixed $parameter = null, int $n = 1): self
    {
        if (is_numeric($parameter)) {
            $articlesFactory = ArticleFactory::make()->times((int)$parameter)->without('Authors');
        } elseif ($parameter instanceof EntityInterface) {
            $articlesFactory = ArticleFactory::makeFrom($parameter)->times($n)->without('Authors');
        } elseif (is_callable($parameter)) {
            $articlesFactory = ArticleFactory::makeWith($parameter)->times($n)->without('Authors');
        } else {
            $articlesFactory = ArticleFactory::make($parameter)->times($n)->without('Authors');
        }

        return $this->with('Articles', $articlesFactory);
    }

    public function withAddress(mixed $parameter = null): self
    {
        if (is_numeric($parameter)) {
            $addressFactory = AddressFactory::make()->times((int)$parameter);
        } elseif ($parameter instanceof EntityInterface) {
            $addressFactory = AddressFactory::makeFrom($parameter);
        } elseif (is_callable($parameter)) {
            $addressFactory = AddressFactory::makeWith($parameter);
        } else {
            $addressFactory = AddressFactory::make($parameter);
        }

        return $this->with('Address', $addressFactory);
    }

    public function fromCountry(string $name): self
    {
        return $this->with('Address.City.Country', CountryFactory::make(compact('name')));
    }
}
