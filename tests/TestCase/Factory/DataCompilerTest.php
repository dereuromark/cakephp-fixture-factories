<?php

declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link https://webrider.de/
 * @since 1.0.0
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace CakephpFixtureFactories\Test\TestCase\Factory;

use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakephpFixtureFactories\Error\FixtureFactoryException;
use CakephpFixtureFactories\Error\PersistenceException;
use CakephpFixtureFactories\Factory\DataCompiler;
use CakephpFixtureFactories\Test\Factory\ArticleFactory;
use CakephpFixtureFactories\Test\Factory\AuthorFactory;
use CakephpFixtureFactories\Test\Factory\CountryFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\Model\Table\PremiumAuthorsTable;

class DataCompilerTest extends TestCase
{
    /**
     * @var \CakephpFixtureFactories\Factory\DataCompiler
     */
    public $authorDataCompiler;

    /**
     * @var \CakephpFixtureFactories\Factory\DataCompiler
     */
    public $articleDataCompiler;

    public function setUp(): void
    {
        $this->authorDataCompiler = new DataCompiler(AuthorFactory::new());
        $this->articleDataCompiler = new DataCompiler(ArticleFactory::new());

        parent::setUp();
    }

    public function testGetMarshallerAssociationNameShouldThrowInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->authorDataCompiler->getMarshallerAssociationName('business_address');
    }

    public function testGetMarshallerAssociationNameShouldReturnUnderscoredAssociationName(): void
    {
        $marshallerAssociationName = $this->authorDataCompiler->getMarshallerAssociationName('BusinessAddress');
        $this->assertSame('business_address', $marshallerAssociationName);
    }

    public function testGetMarshallerAssociationNameWithDottedAssociation(): void
    {
        $marshallerAssociationName = $this->authorDataCompiler->getMarshallerAssociationName('BusinessAddress.City.Countries');
        $this->assertSame('business_address.city.country', $marshallerAssociationName);
    }

    public function testGetMarshallerAssociationNameWithAliasedAssociationName(): void
    {
        $marshallerAssociationName = $this->articleDataCompiler->getMarshallerAssociationName('ExclusivePremiumAuthors');
        $this->assertSame(PremiumAuthorsTable::ASSOCIATION_ALIAS, $marshallerAssociationName);
    }

    public function testGetMarshallerAssociationNameWithAliasedDeepAssociationName(): void
    {
        $marshallerAssociationName = $this->articleDataCompiler->getMarshallerAssociationName('ExclusivePremiumAuthors.Address');
        $this->assertSame(PremiumAuthorsTable::ASSOCIATION_ALIAS . '.address', $marshallerAssociationName);
    }

    public function testGenerateRandomPrimaryKeyInteger(): void
    {
        $this->assertTrue(is_int($this->articleDataCompiler->generateRandomPrimaryKey('integer')));
    }

    public function testGenerateRandomPrimaryKeyBigInteger(): void
    {
        $this->assertTrue(is_int($this->articleDataCompiler->generateRandomPrimaryKey('biginteger')));
    }

    public function testGenerateRandomPrimaryKeyUuid(): void
    {
        $this->assertTrue(is_string($this->articleDataCompiler->generateRandomPrimaryKey('uuid')));
    }

    /**
     * Unknown column types now throw rather than silently producing a 32-bit
     * int, which masks misconfigured schemas. Users hitting this should pass
     * an explicit primary key via setPrimaryKeyOffset().
     */
    public function testGenerateRandomPrimaryKeyUnknownColumnTypeThrows(): void
    {
        $this->expectException(FixtureFactoryException::class);
        $this->expectExceptionMessage('Cannot generate a random primary key for column type `foo`');

        $this->articleDataCompiler->generateRandomPrimaryKey('foo');
    }

    public function testGenerateRandomPrimaryKeyMediuminteger(): void
    {
        $this->assertTrue(is_int($this->articleDataCompiler->generateRandomPrimaryKey('mediuminteger')));
    }

    public function testGenerateArrayOfRandomPrimaryKeys(): void
    {
        $res = $this->articleDataCompiler->generateArrayOfRandomPrimaryKeys();
        $this->assertTrue(is_int($res['id']));
        $this->assertSame(1, count($res));
    }

    public function testCreatePrimaryKeyOffset(): void
    {
        $res = $this->articleDataCompiler->createPrimaryKeyOffset();
        $this->assertTrue(is_int($res['id']));
        $this->assertSame(1, count($res));

        $this->expectException(PersistenceException::class);
        $this->articleDataCompiler->createPrimaryKeyOffset();
    }

    public function testSetPrimaryKey(): void
    {
        $data = CountryFactory::new()->build();

        $this->articleDataCompiler->startPersistMode();
        $res = $this->articleDataCompiler->setPrimaryKey($data);
        $this->articleDataCompiler->endPersistMode();
        $this->assertTrue(is_int($res['id']));
    }

    /**
     * If the id is set be the user, the primary key is set to this id
     * No random primary key is generated
     */
    public function testSetPrimaryKeyWithIdSet(): void
    {
        $id = rand(1, 10000);
        $entity = new Entity(compact('id'));
        $res = $this->articleDataCompiler->setPrimaryKey($entity);
        $this->assertSame($id, $res['id']);
    }

    public function testSetPrimaryKeyOnEntity(): void
    {
        $country = CountryFactory::new()->build();

        $this->articleDataCompiler->startPersistMode();
        $res = $this->articleDataCompiler->setPrimaryKey($country);

        $this->assertTrue(is_int($res['id']));

        $this->articleDataCompiler->endPersistMode();
    }

    public static function dataForGetModifiedUniqueFields(): array
    {
        return [
            [[], []],
            [['id' => 'Foo'], ['id']],
            [['id' => 'Foo', 'name' => 'Bar'], ['id']],
            [['id' => 'Foo', 'name' => 'Bar', 'unique_stamp' => 'FooBar'], ['id', 'unique_stamp']],
        ];
    }

    /**
     * @param array $injectedData
     * @param array $expected
     */
    #[DataProvider('dataForGetModifiedUniqueFields')]
    public function testGetModifiedUniqueFields(array $injectedData, array $expected): void
    {
        $dataCompiler = new DataCompiler(CountryFactory::new($injectedData));
        $dataCompiler->compileEntity($injectedData);
        $this->assertSame($dataCompiler->getModifiedUniqueFields(), $expected);
    }

    public function testCompileEntityWithoutSetters(): void
    {
        $value = 'Foo';
        $dataCompiler = new DataCompiler(AuthorFactory::new()->without('Address'));
        $dataCompiler->setSkippedSetters(['field_with_setter_1']);
        /** @var \TestApp\Model\Entity\Author $author */
        $author = $dataCompiler->compileEntity([
            'field_with_setter_1' => $value,
            'field_with_setter_2' => $value,
            'field_with_setter_3' => $value,
        ]);

        $this->assertSame($value, $author->get('field_with_setter_1'));
        $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_2'));
        $this->assertSame($author->prependPrefixToField($value), $author->get('field_with_setter_3'));
    }

    public function testEntityHasRegistryAlias(): void
    {
        $country = CountryFactory::new()->build();
        $this->assertSame('Countries', $country->getSource());
    }
}
