<?php
/**
 * Created by PhpStorm.
 * User: supreme
 * Date: 04.05.14
 * Time: 12:27
 */

namespace wbl\db\models;

use Yii;
use yii\db\ActiveRecord as BaseActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;

/**
 * Class ActiveRecord
 * @package wbl\db\models
 *
 * Описание
 * Эта модель значительно расширяет функционал связей базовой модели @class ActiveRecord.
 * С помощью этой модели можно сохранять/удалять зависимые модели получаемые при помощи @method $this->hasMany() по методу in-one-touch.
 *
 * Пример
 *  // создаем экземпляр необходимой модели
 * 	$model = new SomeRecord;
 *
 * 	// загружаем модель из post запроса
 *	$post = Yii::$app->request->post();
 *	if($model->load($post)) {
 * 		// загружаем измененный список моделей связи на основе старого списка
 *		$model->relatedRecords = RelatedRecord::loadMultipleDiff($model->relatedRecords, $post);
 *
 * 		// сохраняем модель, а затем и зависимые модели
 *		$model->save();
 *	}
 */
class ActiveRecord extends BaseActiveRecord {

	/**
	 * @var array[[class, link], ...]
	 */
	private $_relations = [];

	/**
	 * @var array
	 */
	private $_relatedOld = [];


	/**
	 * @inheritdoc
	 */
	public function __set($name, $value) {
		if(isset($this->_relations[$name])) {
			$this->setRelation($name, $value);
		} else {
			parent::__set($name, $value);
		}
	}


	/**
	 * Регистрация связи.
	 * @inheritdoc
	 */
	public function hasMany($class, $link) {
		// получаем метод из которого была вызвана конструкция
		list(, $caller) = debug_backtrace(false);
		$method = $caller['function'];

		// регистрируем связь
		if(substr($method, 0, 3) == 'get') {
			$name = substr($method, 3);
			$name[0] = strtolower($name[0]);
			$this->registerRelation($class, $name, $link);
		}

		return parent::hasMany($class, $link);
	}


	/**
	 * Валидация модели и связей.
	 * @inheritdoc
	 */
	public function validate($attributeNames = null, $clearErrors = true) {
		return parent::validate($attributeNames) && $this->validateRelations();
	}

	/**
	 * Сохранение модели и связей.
	 * @inheritdoc
	 */
	public function save($runValidation = true, $attributeNames = null) {
		// валидируем
		if($runValidation && !$this->validate($attributeNames)) {
			Yii::info('Model not inserted due to validation error.', __METHOD__);

			return false;
		}

		// сохраняем
		return parent::save(false, $attributeNames) && $this->saveRelations(false);
	}

	/**
	 * Удаление моедли и связей.
	 * @inheritdoc
	 */
	public function delete() {
		return parent::delete() && $this->deleteRelations();
	}


	/**
	 * Регистрирует связь.
	 * С этого момента она начинает отслеживаться и с ней можно выполнять операции сохранения/удаления зависимых моделей.
	 * @param $class
	 * @param $name
	 * @param $link
	 */
	public function registerRelation($class, $name, $link) {
		$this->_relations[$name] = [$class, $link];
	}

	/**
	 * @inheritdoc
	 */
	public function getRelation($name, $throwException = true) {
		return parent::getRelation($name, $throwException);
	}

	/**
	 * Устанавливает все зависимые модели связи.
	 * @param $name
	 * @param $records
	 */
	public function setRelation($name, $records) {
		$this->_relatedOld[$name] = $this->$name;
		parent::populateRelation($name, $records);
	}

	/**
	 * Выполняет валидацию всех зависимых моделей связи.
	 * @param $name
	 * @param null $attributeNames
	 * @param bool $clearErrors
	 * @return bool
	 */
	public function validateRelation($name, $attributeNames = null, $clearErrors = true) {
		$valid = true;

		if($models = $this->$name) {
			// получаем внешний ключ модели
			$key = key($this->_relations[$name][1]);

			// определяем список атрибутов без внешшнего ключа
			$attributeNames = array_flip($attributeNames ? : reset($models)->attributes());
			if(isset($attributeNames[$key])) {
				unset($attributeNames[$key]);
			}
			$attributeNames = array_flip($attributeNames);

			// валидируем кастомные атрибуты моделей
			/** @var ActiveRecord $record */
			foreach($this->$name as $record) {
				!$record->validate($attributeNames, $clearErrors) && ($valid = false);
			}
		}

		return $valid;
	}

	/**
	 * Сохзраняет изменения во всех зависимых моделях связи.
	 * @param $name
	 * @param bool $runValidation
	 * @param null $attributeNames
	 * @return bool
	 */
	public function saveRelation($name, $runValidation = true, $attributeNames = null) {
		/** @var ActiveRecord $record */
		$success = true;

		// получаем списки моделей
		$recordsOld = $this->_relatedOld[$name];
		$recordsNew = $this->$name;

		// сохраняем модели
		$link = $this->_relations[$name][1];
		foreach($recordsNew as $record) {
			$record->{key($link)} = $this->{reset($link)};
			!$record->save($runValidation, $attributeNames) && ($success = false);
		}

		// находим удаляемые модели
		$recordsOld = ArrayHelper::index($recordsOld, 'id');
		$recordsNew = ArrayHelper::index($recordsNew, 'id');
		$recordsDelete = array_diff_key($recordsOld, $recordsNew);

		// удаляем модели
		foreach($recordsDelete as $record) {
			!$record->delete() && ($success = false);
		}

		return $success;
	}

	/**
	 * Удаляет все зависимые модели связи.
	 * @param $name
	 * @return bool
	 */
	public function deleteRelation($name) {
		$success = true;
		foreach($this->$name as $model) {
			/** @var ActiveRecord $model */
			!$model->delete() && ($success = false);
		}

		return $success;
	}


	/**
	 * Валидирует все связи.
	 * @return bool
	 */
	public function validateRelations() {
		$valid = true;
		foreach($this->_relatedOld as $name => &$b) {
			!$this->validateRelation($name, null, true) && ($valid = false);
		}

		return $valid;
	}

	/**
	 * Сохраняет все связи.
	 * @param bool $runValidation
	 * @return bool
	 */
	public function saveRelations($runValidation = true) {
		// валидация связей
		if($runValidation && !$this->validateRelations()) {
			Yii::info('Relations not saved due to validation error.', __METHOD__);

			return false;
		}

		// сохранение связей
		$success = true;
		foreach($this->_relatedOld as $name => &$b) {
			!$this->saveRelation($name, false, null) && ($success = false);
		}

		return $success;
	}

	/**
	 * Удаляет все связи.
	 * @return bool
	 */
	public function deleteRelations() {
		$success = true;
		foreach($this->_relatedOld as $name => &$b) {
			!$this->deleteRelation($name) && ($success = false);
		}

		return $success;
	}


	/**
	 * Метод позволяет загрузить сразу несколько моделей, что позволяет использовать его в табличном вводе.
	 * @param ActiveRecord[] $records
	 * @param array $data
	 * @param string $formName
	 * @return ActiveRecord[]
	 */
	public static function loadMultipleDiff($records, $data, $formName = null) {
		/** @var ActiveRecord $record */
		$class = self::className();
		$record = reset($records) ? : new $class;

		// получаем список элементов
		$scope = $formName === null ? $record->formName() : $formName;
		if($scope != '') {
			$data = $data && isset($data[$scope]) ? $data[$scope] : null;
		}

		// собираем коллекцию элементов
		$recordsOld = ArrayHelper::index($records, 'id');
		$recordsNew = [];
		if($data && is_array($data)) {
			$class = get_class($record);
			foreach($data as $item) {
				$record = isset($item['id']) && isset($recordsOld[$item['id']]) ? $recordsOld[$item['id']] : new $class;
				$record->setAttributes($item);
				$recordsNew[] = $record;
			}
		}

		return $recordsNew;
	}
}