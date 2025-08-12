<?php
declare(strict_types=1);

/**
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) 2020 Juan Pablo Ramirez and Nicolas Masson
 * @link          https://webrider.de/
 * @since         3.1.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace CakephpFixtureFactories\Generator;

/**
 * Interface for fake data generators
 *
 * This interface provides an abstraction layer for different faker libraries
 * allowing seamless switching between implementations like Faker and DummyGenerator
 *
 * @method string name() Generate a random name
 * @method string firstName() Generate a random first name
 * @method string lastName() Generate a random last name
 * @method string email() Generate a random email address
 * @method string safeEmail() Generate a random safe email address
 * @method string phoneNumber() Generate a random phone number
 * @method string address() Generate a random address
 * @method string city() Generate a random city name
 * @method string postcode() Generate a random postcode
 * @method string country() Generate a random country
 * @method string streetName() Generate a random street name
 * @method string streetAddress() Generate a random street address
 * @method string company() Generate a random company name
 * @method string jobTitle() Generate a random job title
 * @method string text(int $maxNbChars = 200) Generate random text
 * @method string realText(int $maxNbChars = 200) Generate random real text
 * @method string sentence(int $nbWords = 6) Generate a random sentence
 * @method string paragraph(int $nbSentences = 3) Generate a random paragraph
 * @method string word() Generate a random word
 * @method array<string> words(int $nb = 3) Generate random words
 * @method int randomNumber(int|null $nbDigits = null) Generate a random number
 * @method int randomDigit() Generate a random digit
 * @method int randomDigitNotNull() Generate a random digit not null
 * @method float randomFloat(int $nbMaxDecimals = null, float $min = 0, float $max = null) Generate a random float
 * @method int numberBetween(int $min = 0, int $max = 2147483647) Generate a number between
 * @method bool boolean(int $chanceOfGettingTrue = 50) Generate a random boolean
 * @method \DateTime dateTime(string $max = 'now') Generate a random DateTime
 * @method \DateTime dateTimeBetween(string $startDate = '-30 years', string $endDate = 'now') Generate a random DateTime between dates
 * @method string date(string $format = 'Y-m-d', string $max = 'now') Generate a random date
 * @method string time(string $format = 'H:i:s', string $max = 'now') Generate a random time
 * @method string url() Generate a random URL
 * @method string userName() Generate a random username
 * @method string password(int $minLength = 6, int $maxLength = 20) Generate a random password
 * @method string uuid() Generate a random UUID
 * @method string ipv4() Generate a random IPv4
 * @method string ipv6() Generate a random IPv6
 * @method string macAddress() Generate a random MAC address
 * @method string userAgent() Generate a random user agent
 * @method string creditCardNumber() Generate a random credit card number
 * @method string creditCardType() Generate a random credit card type
 * @method string iban() Generate a random IBAN
 * @method string swiftBicNumber() Generate a random SWIFT BIC number
 * @method string hexColor() Generate a random hex color
 * @method string safeHexColor() Generate a random safe hex color
 * @method string rgbColor() Generate a random RGB color
 * @method array<int> rgbColorAsArray() Generate a random RGB color as array
 * @method string hslColor() Generate a random HSL color
 * @method array<int> hslColorAsArray() Generate a random HSL color as array
 * @method string colorName() Generate a random color name
 * @method string mimeType() Generate a random MIME type
 * @method string fileExtension() Generate a random file extension
 * @method string file(string $sourceDir = '/tmp', string $targetDir = '/tmp') Copy a random file
 * @method string imageUrl(int $width = 640, int $height = 480) Generate a random image URL
 * @method string image(string $dir = null, int $width = 640, int $height = 480) Generate a random image
 * @method string md5() Generate a random MD5
 * @method string sha1() Generate a random SHA1
 * @method string sha256() Generate a random SHA256
 * @method string locale() Generate a random locale
 * @method string countryCode() Generate a random country code
 * @method string languageCode() Generate a random language code
 * @method string currencyCode() Generate a random currency code
 * @method mixed randomElement(array<mixed> $array = ['a', 'b', 'c']) Get a random element from array
 * @method array<mixed> randomElements(array<mixed> $array = ['a', 'b', 'c'], int $count = 1) Get random elements from array
 * @method array<mixed> shuffleArray(array<mixed> $array = []) Shuffle an array
 * @method string shuffleString(string $string = '') Shuffle a string
 * @method string numerify(string $string = '###') Generate random numbers in string
 * @method string lexify(string $string = '????') Generate random letters in string
 * @method string bothify(string $string = '## ??') Generate random numbers and letters in string
 * @method string asciify(string $string = '****') Generate random ASCII characters in string
 * @method string regexify(string $regex = '') Generate string matching regex
 * @method string|int enumValue(string $enumClass) Get a random value from a BackedEnum
 * @method \BackedEnum enumElement(string $enumClass) Get a random enum element
 */
interface GeneratorInterface
{
    /**
     * Seed the random number generator
     *
     * @param int|null $seed The seed value
     * @return void
     */
    public function seed(?int $seed = null): void;

    /**
     * Magic method to handle dynamic property access
     *
     * @param string $property Property name
     * @return mixed
     */
    public function __get(string $property): mixed;

    /**
     * Magic method to handle dynamic method calls
     *
     * @param string $name Method name
     * @param array<mixed> $arguments Method arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed;

    /**
     * Get a unique instance of the generator
     *
     * @return \CakephpFixtureFactories\Generator\UniqueGeneratorInterface
     */
    public function unique(): UniqueGeneratorInterface;

    /**
     * Get an optional instance of the generator
     *
     * @param float $weight Weight between 0 and 1
     * @return \CakephpFixtureFactories\Generator\OptionalGeneratorInterface
     */
    public function optional(float $weight = 0.5): OptionalGeneratorInterface;
}
