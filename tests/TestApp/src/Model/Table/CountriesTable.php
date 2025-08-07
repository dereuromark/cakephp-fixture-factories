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
namespace TestApp\Model\Table;

use ArrayObject;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class CountriesTable extends Table
{
    public const NAME_MAX_LENGTH = 100;

    public function initialize(array $config): void
    {
        $this
            ->addBehavior('Timestamp')
            ->addBehavior('Sluggable', [
                'field' => 'name',
            ])
            ->addBehavior('TestPlugin.SomePlugin');

        $this->addAssociations([
            'hasMany' => [
                'Cities',
                'VirtualCities' => [
                    'className' => 'Cities',
                    'foreignKey' => 'city_id',
                ],
            ],
        ]);

        parent::initialize($config);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->maxLength('name', self::NAME_MAX_LENGTH);

        return $validator;
    }

    /**
     * @param \Cake\Event\EventInterface $event
     * @param \ArrayObject $data
     * @param \ArrayObject $options
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        $data['beforeMarshalTriggered'] = true;
    }
}
