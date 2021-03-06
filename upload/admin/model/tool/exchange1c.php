<?php

class ModelToolExchange1c extends Model {

	private $VERSION_XML 	= '';
	private $STORE_ID		= 0;
	private $LANG_ID		= 0;
	private $FULL_IMPORT	= false;
	private $NOW 			= '';
	private $TAB_FIELDS		= array();



	/**
	 * ****************************** ОБЩИЕ ФУНКЦИИ ******************************
	 */


	/**
	 * Номер текущей версии
	 *
	 */
	public function version() {
		return "1.6.2.b9";
	} // version()


	/**
	 * Пишет информацию в файл журнала
	 *
	 * @param	int				Уровень сообщения
	 * @param	string,object	Сообщение или объект
	 */
	private function log($message, $level = 1, $line = '') {
		if ($level <= $this->config->get('exchange1c_log_level')) {

			$memory_usage = '';
			if ($this->config->get('exchange1c_log_memory_use_view') == 1) {
				$memory_size = memory_get_usage() / 1024 / 1024;
				$memory_usage = sprintf("%.3f", $memory_size) . " Mb | ";
			}

			if ($this->config->get('exchange1c_log_debug_line_view') == 1) {
				if (!$line) {
					list ($di) = debug_backtrace();
					$line = sprintf("%04s",$di["line"]) . " | ";
				} else {
					$line .= " | ";
				}
			} else {
				$line = '';
			}

			if (is_array($message) || is_object($message)) {
				$this->log->write($memory_usage . $line);
				$this->log->write(print_r($message, true));
			} else {
				$this->log->write($memory_usage . $line . $message);
			}
		}
	} // log()


	/**
	 * Конвертирует XML в массив
	 *
	 * @param	array				data
	 * @param	SimpleXMLElement	XML
	 * @return	XML
	 */
	function array_to_xml($data, &$xml) {
		foreach($data as $key => $value) {
			if (is_array($value)) {
				if (!is_numeric($key)) {
					$subnode = $xml->addChild(preg_replace('/\d/', '', $key));
					$this->array_to_xml($value, $subnode);
				}
			}
			else {
				$xml->addChild($key, $value);
			}
		}
		return $xml;
	} // array_to_xml()


	/**
	 * Очистка лога
	 */
	private function clearLog() {

		$file = DIR_LOGS . $this->config->get('config_error_filename');
		$handle = fopen($file, 'w+');
		fclose($handle);

	} // clearLog()


	/**
	 * Возвращает строку даты
	 *
	 * @param	string	var
	 * @return	string
	 */
	function format($var){
		return preg_replace_callback(
		    '/\\\u([0-9a-fA-F]{4})/',
		    create_function('$match', 'return mb_convert_encoding("&#" . intval($match[1], 16) . ";", "UTF-8", "HTML-ENTITIES");'),
		    json_encode($var)
		);
	} // format()


	/**
	 * Выполняет запрос, записывает в лог в режим отладки и возвращает результат
	 */
	function query($sql){

		if ($this->config->get('exchange1c_log_debug_line_view') == 1) {
			list ($di) = debug_backtrace();
			$line = sprintf("%04s",$di["line"]);
		} else {
			$line = '';
		}

		$this->log($sql, 3, $line);
		return $this->db->query($sql);

	} // query()


	/**
	 * Проверим файл на стандарт Commerce ML
	 */
	private function checkCML($xml) {
		$this->log("Проверка XML",2);
		if ($xml['ВерсияСхемы']) {
			$this->VERSION_XML = (string)$xml['ВерсияСхемы'];
			$this->log("[i] Версия XML: " . $this->VERSION_XML,2);
		} else {
			return "Файл не является стандартом Commerce ML!";
		}
		return "";
	} // checkCML()


	/**
	 * Проверка на существование поля в таблице
	 */
	public function existField($table, $field, $value="") {

		if (!$this->existTable($table)) return 0;
		$query = $this->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` WHERE `field` = '" . $field . "'");
		if ($query->num_rows) {
			return empty($value) ? 1 : ", " . $field . " = '" . $value . "'";
		}

		return empty($value) ? 0 : "";

	} // existField()


	/**
	 * Проверка на существование таблицы
	 */
	private function existTable($table) {

		$query = $this->query("SHOW TABLES LIKE '" . DB_PREFIX . $table . "'");
		return $query->num_rows ? 1 : 0;

	} // existTable()


	/**
	 * Определение дополнительных полей и запись их в глобальную переменную типа массив
	 */
	private function defineAdditionalFields() {

		$this->log("Поиск в базе данных дополнительных полей",2);

		$tables = array(
			'manufacturer'				=> array('noindex'=>1),
			'product_to_category'		=> array('main_category'=>1),
			'product_description'		=> array('meta_h1'=>''),
			'manufacturer_description'	=> array('name'=>''),
			'product'					=> array('noindex'=>1),
			'order'						=> array('payment_inn'=>'','shipping_inn'=>'','patronymic'=>'','payment_patronymic'=>'','shipping_patronymic'=>''),
			'customer'					=> array('patronymic'=>''),
			'cart'						=> array('product_feature_id'=>0,'unit_id'=>0)
		);

		foreach ($tables as $table => $fields) {

			$query = $this->query("SHOW TABLES LIKE '" . DB_PREFIX . $table . "'");
			if (!$query->num_rows) continue;

			$this->TAB_FIELDS[$table] = array();

			foreach ($fields as $field => $value) {

				$query = $this->query("SHOW COLUMNS FROM `" . DB_PREFIX . $table . "` WHERE `field` = '" . $field . "'");
				if (!$query->num_rows) continue;

				$this->TAB_FIELDS[$table][$field] = $value;
			}
		}
		$this->log($this->TAB_FIELDS, 2);
		return "";
	} // defineAdditionalFields()


	/**
	 * Очищает базу
	 */
	public function cleanDB() {
		$this->log("==> cleanDB()",2);
		// Удаляем товары
		$result = "";

		$this->log("[i] Очистка таблиц товаров...",2);
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_attribute`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_description`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_discount`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_image`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_option`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_option_value`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_related`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_reward`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_special`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_quantity`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_1c`');
		$result .=  "Очищены таблицы товаров\n";

		//SEO
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'url_alias`');

		// Характеристики (группы опций)
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_feature`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_price`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_feature_value`');

		// Дополнительные единицы измерений товара
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_unit`');

		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_category`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_download`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_layout`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_store`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'option_value_description`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'option_description`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'option_value`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'order_option`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'option`');
		$this->query('DELETE FROM ' . DB_PREFIX . 'url_alias WHERE query LIKE "%product_id=%"');
		$result .=  "Очищены таблицы товаров, опций\n";

		// Очищает таблицы категорий
		$this->log("Очистка таблиц категорий...",2);
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'category');
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'category_description');
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'category_to_store');
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'category_to_layout');
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'category_path');
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'category_to_1c');
		$this->query('DELETE FROM ' . DB_PREFIX . 'url_alias WHERE query LIKE "%category_id=%"');
		$result .=  "Очищены таблицы категорий\n";

		// Очищает таблицы от всех производителей
		$this->log("Очистка таблиц производителей...",2);
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'manufacturer');
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'manufacturer_to_1c');
		$query = $this->query("SHOW TABLES FROM `" . DB_DATABASE . "` WHERE `Tables_in_" . DB_DATABASE . "` LIKE '" . DB_PREFIX . "manufacturer_description'");
		//$query = $this->db->query("SHOW TABLES FROM " . DB_DATABASE . " LIKE '" . DB_PREFIX . "manufacturer_description'");
		if ($query->num_rows) {
			$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'manufacturer_description');
		}
		$this->query('TRUNCATE TABLE ' . DB_PREFIX . 'manufacturer_to_store');
		$this->query('DELETE FROM ' . DB_PREFIX . 'url_alias WHERE query LIKE "%manufacturer_id=%"');
		$result .=  "Очищены таблицы производителей\n";

		// Очищает атрибуты
		$this->log("Очистка таблиц атрибутов...",2);
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "attribute`");
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "attribute_description`");
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "attribute_to_1c`");
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "attribute_group`");
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "attribute_group_description`");
		$result .=  "Очищены таблицы атрибутов\n";

		// Выставляем кол-во товаров в 0
		$this->log("Очистка остатков...",2);
		$this->query("UPDATE `" . DB_PREFIX . "product` SET `quantity` = 0");
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "warehouse`");
		$result .=  "Обнулены все остатки\n";

		// Удаляем все цены
		$this->log("Очистка остатков...",2);
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "product_price`");
		$result .=  "Удалены все цены\n";

		// Удаляем все характеристики
		$this->log("Очистка характеристик...",2);
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_feature`');
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_feature_value`');
		$result .=  "Удалены все характеристики\n";

		// Удаляем связи с магазинами
		$this->log("Очистка связей с магазинами...",2);
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'store_to_1c`');
		$result .=  "Удалены все связи с магазинами\n";

		// Удаляем связи с единицами измерений
		$this->log("Очистка связей с единицами измерений...",2);
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'unit_to_1c`');
		$result .=  "Очищены таблицы связей с единицами измерений\n";

		return $result;
	} // cleanDB()


	/**
	 * Очищает базу
	 */
	public function cleanLinks() {
		$this->log("==> cleanLinks()",2);
		// Удаляем связи
		$result = "";

		$this->log("[i] Очистка таблиц товаров...",2);
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'product_to_1c`');
		$result .=  "Таблица связей товаров '" . DB_PREFIX . "product_to_1c'\n";
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'category_to_1c`');
		$result .=  "Таблица связей категорий '" . DB_PREFIX . "category_to_1c'\n";
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'manufacturer_to_1c`');
		$result .=  "Таблица связей производителей '" . DB_PREFIX . "manufacturer_to_1c'\n";
		$this->query("TRUNCATE TABLE `" . DB_PREFIX . "attribute_to_1c`");
		$result .=  "Таблица связей атрибутов '" . DB_PREFIX . "attribute_to_1c'\n";
		$this->query('TRUNCATE TABLE `' . DB_PREFIX . 'store_to_1c`');
		$result .=  "Таблица связей с магазинами\n";

		return $result;
	} // cleanLinks()


	/**
	 * Возвращает информацию о синхронизированных объектов с 1С товарок, категорий, атрибутов
	 */
	public function linksInfo() {
		$this->log("==> linksInfo()",2);
		$data = array();
		$query = $this->query('SELECT count(*) as num FROM `' . DB_PREFIX . 'product_to_1c`');
		$data['product_to_1c'] = $query->row['num'];
		$query = $this->query('SELECT count(*) as num FROM `' . DB_PREFIX . 'category_to_1c`');
		$data['category_to_1c'] = $query->row['num'];
		$query = $this->query('SELECT count(*) as num FROM `' . DB_PREFIX . 'manufacturer_to_1c`');
		$data['manufacturer_to_1c'] = $query->row['num'];
		$query = $this->query('SELECT count(*) as num FROM `' . DB_PREFIX . 'attribute_to_1c`');
		$data['attribute_to_1c'] = $query->row['num'];

		return $data;

	} // linksInfo()


	/**
	 * Удаляет связи cml_id -> id
	 */
	public function deleteLinkProduct($product_id) {

		$this->log("Удалеие связи с ТС у товара product_id: " . $product_id, 2);

		// Удаляем линк
		if ($product_id){
			$this->query("DELETE FROM `" .  DB_PREFIX . "product_to_1c` WHERE `product_id` = " . (int)$product_id);
			$this->log("> Удалена связь с товаром", 2);
		}
		$this->load->model('catalog/product');
		$product = $this->model_catalog_product->getProduct($product_id);
		if ($product['image']) {
			// Удаляем только в папке import_files
			if (substr($product['image'], 0, 12) == "import_files") {
				unlink(DIR_IMAGE . $product['image']);
				$this->log("Удален файл основной картинки: " . $product['image'], 2);
			}
		}
		$productImages = $this->model_catalog_product->getProductImages($product_id);
		foreach ($productImages as $image) {
			// Удаляем только в папке import_files
			if (substr($image['image'], 0, 12) == "import_files") {
				unlink(DIR_IMAGE . $image['image']);
				$this->log("Удален файл дополнительной картинки: " . $image['image'],2);
			}
		}

	} // deleteLinkProduct()


	/**
	 * Удаляет связи cml_id -> id
	 */
	public function deleteLinkCategory($category_id) {

		// Удаляем линк
		if ($category_id){
			$this->query("DELETE FROM `" .  DB_PREFIX . "category_to_1c` WHERE `category_id` = " . (int)$category_id);
			$this->log("Удалена связь категории category_id: " . $category_id,2);
		}

	} //  deleteLinkCategory()


	/**
	 * Удаляет связи cml_id -> id
	 */
	public function deleteLinkManufacturer($manufacturer_id) {

		// Удаляем линк
		if ($manufacturer_id){
			$this->query("DELETE FROM `" .  DB_PREFIX . "manufacturer_to_1c` WHERE `manufacturer_id` = " . $manufacturer_id);
			$this->log("Удалена связь производителя manufacturer_id: " . $manufacturer_id,2);
		}
	} //  deleteLinkManufacturer()


	/**
	 * Удаляет связи товара c характеристиками 1С
	 */
	public function deleteLinkFeature($product_id) {
		// Удаляем линк
		if ($product_id){
			$this->query("DELETE FROM `" .  DB_PREFIX . "product_feature` WHERE `product_id` = " . $product_id);
			$this->query("DELETE FROM `" .  DB_PREFIX . "product_feature_value` WHERE `product_id` = " . $product_id);
			$this->log("Удалена связь характеристики с товаром product_id: " . $product_id,2);
		}
	} //  deleteLinkFeature()


	/**
	 * Создает события
	 */
	public function setEvents() {
		$this->log("==> setEvents()",2);
		// Установка событий
		$this->load->model('extension/event');

		// Удалим все события
		$this->model_extension_event->deleteEvent('exchange1c');

		// Добавим удаление связей при удалении товара
		$this->model_extension_event->addEvent('exchange1c', 'pre.admin.product.delete', 'module/exchange1c/eventDeleteProduct');
		// Добавим удаление связей при удалении категории
		$this->model_extension_event->addEvent('exchange1c', 'pre.admin.category.delete', 'module/exchange1c/eventDeleteCategory');
		// Добавим удаление связей при удалении Производителя
		$this->model_extension_event->addEvent('exchange1c', 'pre.admin.manufacturer.delete', 'module/exchange1c/eventDeleteManufacturer');
		// Добавим удаление связей при удалении Характеристики
		$this->model_extension_event->addEvent('exchange1c', 'pre.admin.option.delete', 'module/exchange1c/eventDeleteOption');
	} // setEvents()


	/**
	 * Получает language_id из code (ru, en, etc)
	 * Как ни странно, подходящей функции в API не нашлось
	 *
	 * @param	string
	 * @return	int
	 */
	public function getLanguageId($lang) {

		$query = $this->query("SELECT `language_id` FROM `" . DB_PREFIX . "language` WHERE `code` = '" . $this->db->escape($lang) . "'");
		$this->LANG_ID = $query->row['language_id'];
		$this->log("Получен language_id: " . $this->LANG_ID);
		return $this->LANG_ID;

	} // getLanguageId()


	/**
	 * Проверяет таблицы модуля
	 */
	public function checkDB() {

		$error = "";
		$tables_module = array("product_to_1c","product_quantity","product_price","product_unit","category_to_1c","warehouse","product_feature","product_feature_value","store_to_1c","attribute_to_1c","manufacturer_to_1c","unit");
		foreach ($tables_module as $table) {
			$query = $this->query("SHOW TABLES FROM `" . DB_DATABASE . "` LIKE '" . DB_PREFIX . "%" . $table . "'");
			if (!$query->rows) {
				$error .= ($error ? "\n" : "") . "Таблица " . $table . " в базе отсутствует!";
			}
		}
		// проверка полей таблиц

		return $error;
	} // checkDB()


	/**
	 * Устанавливает SEO URL (ЧПУ) для заданного товара
	 * @param 	inf
	 * @param 	string
	 */
	private function setSeoURL($url_type, $element_id, $element_name) {

		$query = $this->query("SELECT `url_alias_id` FROM `" . DB_PREFIX . "url_alias` WHERE `query` = '" . $url_type . "=" . $element_id . "'");
		if ($query->num_rows) {
			return;
		}
		$this->query("INSERT INTO `" . DB_PREFIX . "url_alias` SET `query` = '" . $url_type . "=" . $element_id ."', `keyword` = '" . $this->db->escape($element_name) . "'");

	} // setSeoURL()


	/**
	 * Транслиетрирует RUS->ENG
	 * @param string $aString
	 * @return string type
	 */
	private function transString($aString) {

		$rus = array(" ", "/", "*", "-", "+", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "_", "+", "[", "]", "{", "}", "~", ";", ":", "'", "\"", "<", ">", ",", ".", "?", "А", "Б", "В", "Г", "Д", "Е", "З", "И", "Й", "К", "Л", "М", "Н", "О", "П", "Р", "С", "Т", "У", "Ф", "Х", "Ъ", "Ы", "Ь", "Э", "а", "б", "в", "г", "д", "е", "з", "и", "й", "к", "л", "м", "н", "о", "п", "р", "с", "т", "у", "ф", "х", "ъ", "ы", "ь", "э", "ё",  "ж",  "ц",  "ч",  "ш",  "щ",   "ю",  "я",  "Ё",  "Ж",  "Ц",  "Ч",  "Ш",  "Щ",   "Ю",  "Я");
		$lat = array("-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-", "-",  "-", "-", "-", "-", "-", "-", "a", "b", "v", "g", "d", "e", "z", "i", "y", "k", "l", "m", "n", "o", "p", "r", "s", "t", "u", "f", "h", "",  "i", "",  "e", "a", "b", "v", "g", "d", "e", "z", "i", "j", "k", "l", "m", "n", "o", "p", "r", "s", "t", "u", "f", "h", "",  "i", "",  "e", "yo", "zh", "ts", "ch", "sh", "sch", "yu", "ya", "yo", "zh", "ts", "ch", "sh", "sch", "yu", "ya");
		$string = str_replace($rus, $lat, $aString);
		while (mb_strpos($string, '--')) {
			$string = str_replace('--', '-', $string);
		}
		$string = strtolower(trim($string, '-'));
		return $string;

	} // transString()


	/**
	 * Формирует строку запроса при наличии переменной
	 */
	private function setStrQuery($field_name, $type) {

		if ($type == 'string') {
			return isset($data[$field_name]) ? ", " . $field_name . " = '" . $this->db->escape($data[$field_name]) . "'" : "";
		}
		elseif ($type == 'int') {
			return isset($data[$field_name]) ? ", " . $field_name . " = " . (int)$data[$field_name] : "";
		}
		elseif ($type == 'float') {
			return isset($data[$field_name]) ? ", " . $field_name . " = " . (float)$data[$field_name] : "";
		}

		return "";

	} //setStrQuery()


	/**
	 * Поиск cml_id товара по ID
	 */
	private function getcml_idByProductId($product_id) {

		$query = $this->query("SELECT 1c_id FROM `" . DB_PREFIX . "product_to_1c` WHERE `product_id` = " . $product_id);
		return isset($query->row['1c_id']) ? $query->row['1c_id'] : '';

	} // getcml_idByProductId()


	/**
	 * ****************************** ФУНКЦИИ ДЛЯ SEO ******************************
	 */

	/**
	 * Получает все категории продукта в строку для SEO
	 */
    private function getProductCategoriesString($product_id) {

 		$categories = array();
		$query = $this->query("SELECT c.category_id, cd.name FROM `" . DB_PREFIX . "category` c LEFT JOIN `" . DB_PREFIX . "category_description` cd ON (c.category_id = cd.category_id) INNER JOIN `" . DB_PREFIX . "product_to_category` pc ON (pc.category_id = c.category_id) WHERE cd.language_id = " . $this->LANG_ID . " AND pc.product_id = " . $product_id . " ORDER BY c.sort_order, cd.name ASC");
		foreach ($query->rows as $category) {
			$categories[] = $category['name'];
		}
		$cat_string = implode(',', $categories);
		$this->log("Получение категории в строку: " . $cat_string);
		return $cat_string;

      } // getProductCategoriesString()


	/**
	 * Получает все категории продукта в массив
	 * первым в массиме будет главная категория
	 */
    private function getProductCategories($product_id) {

		$main_category = isset($this->TAB_FIELDS['product_to_category']['main_category']) ? ",`main_category`" : "";
		$query = $this->query("SELECT `category_id`" . $main_category . " FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = " . $product_id);
		$categories = array();
		foreach ($query->rows as $category) {
			if ($main_category && $category['main_category']) {
				// главную категорию добавляем в начало массива
				array_unshift($categories, $category['category_id']);
			} else {
				$categories[] = $category['category_id'];
			}
		}
		$this->log("> Получены категории товара");
		return $categories;

    } // getProductCategories()


	/**
	 * Генерит SEO строк
	 */
	private function seoGenerateString($template, $product_tags, $trans = false) {

		// Выберем все теги которые используются в шаблоне
		preg_match_all('/\{(\w+)\}/', $template, $matches);
		$values = array();

		foreach ($matches[0] as $match) {
			$value = isset($product_tags[$match]) ? $product_tags[$match] : '';
			if ($trans) {
				$values[] = $this->transString($value);
			} else {
				$values[] = $value;
			}
		}
		$seo_string = str_replace($matches[0], $values, $template);
		$this->log("> Сформирована строка SEO: " . $seo_string);
		return $seo_string;

	} // seoGenerateStr()


	/**
	 * Генерит SEO переменные шаблона для товара
	 */
	private function seoGenerateProduct(&$data) {

		$this->log("==> Начато формирование SEO для товара", 2);

		// Товары, Категории
		$seo_fields = array(
			'seo_url'			=> array('trans' => true),
			'meta_title'		=> array(),
			'meta_description'	=> array(),
			'meta_keyword'		=> array(),
			'tag'				=> array()
		);

		// Сопоставляем значения
		$tags = array(
			'{name}'		=> isset($data['name']) 		? $data['name'] 								: '',
			'{sku}'			=> isset($data['sku'])			? $data['sku'] 									: '',
			'{brand}'		=> isset($data['manufacturer'])	? $data['manufacturer'] 						: '',
			'{desc}'		=> isset($data['description'])	? $data['description'] 							: '',
			'{cats}'		=> isset($data['categories'])	? $data['categories'] 							: '',
			'{price}'		=> isset($data['price'])		? $this->currency->format($data['price']) 		: '',
			'{prod_id}'		=> isset($data['product_id'])	? $data['product_id'] 							: '',
			'{cat_id}'		=> isset($data['category_id'])	? $data['category_id'] 							: ''
		);

		if (isset($this->TAB_FIELDS['product_description']['meta_h1'])) {
			$seo_fields['meta_h1'] = array();
		}

		// Получим поля для сравнения
		$fields_list = array();
		foreach ($seo_fields as $field=>$param) {
			if ($field == 'seo_url') continue;
			$fields_list[] = $field;
		}
		$fields	= implode($fields_list,', ');
		if (!isset($data['name']))
			$fields .= ", name";
		$query = $this->query("SELECT " . $fields . " FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = " . $data['product_id'] . " AND `language_id` = " . $this->LANG_ID);
		if ($query->num_rows) {
			foreach ($fields_list as $field) {
				$data[$field] = $query->row[$field];
				$this->log('field: '.$field,2);
			}
		}
		if (!isset($data['name']) && isset($query->row['name'])) {
			$data['name'] = $query->row['name'];
		$tags['{name}']	= $data['name'];
		}

		// Формируем массив с замененными значениями
		foreach ($seo_fields as $field=>$param) {
			$template = '';
			if ($this->config->get('exchange1c_seo_product_'.$field) == 'template') {
				$template = $this->config->get('exchange1c_seo_product_'.$field.'_template');
			} elseif ($this->config->get('exchange1c_seo_product_'.$field) == 'import') {
				// из свойства которое считалось при обмене
			}
			if ($this->config->get('exchange1c_seo_product_overwrite') == 'overwrite') {
				// Перезаписывать
				$data[$field] = $this->seoGenerateString($template, $tags, isset($param['trans']));
			} else {
				// Только если поле пустое
				if (empty($data[$field])) {
					$data[$field] = $this->seoGenerateString($template, $tags, isset($param['trans']));
				} else {
					$this->log("Поле '" . $field . "' не пустое", 2);
				}
			}
			$this->log("Поле '" . $field . "' = '" . $data[$field] . "'", 2);
		}

		if (isset($data['seo_url'])) {
			if ($this->config->get('exchange1c_seo_product_overwrite') == 'overwrite') {
				$this->setSeoURL('product_id', $data['product_id'], $data['seo_url']);
			} else {
				$query = $this->query("SELECT keyword FROM `" . DB_PREFIX . "url_alias` WHERE `query` = 'product_id=" . $data['product_id'] . "'");
				if ($query->num_rows) {
					$data['seo_url'] = $query->row['keyword'];
					if (empty($data['seo_url']))
						$this->setSeoURL('product_id', $data['product_id'], $data['seo_url']);
				} else {
					$this->setSeoURL('product_id', $data['product_id'], $data['seo_url']);
				}
			}
		}
		$this->log("<== Завершено формирование SEO для товара product_id: " . $data['product_id']);

	} // seoGenerateProduct()


	/**
	 * Генерит SEO переменные шаблона для категории
	 */
	private function seoGenerateCategory(&$data) {

		$this->log("==> Начато формирование SEO для категории", 2);

		// Товары, Категории
		$seo_fields = array(
			'seo_url'			=> array('trans' => true),
			'meta_title'		=> array(),
			'meta_description'	=> array(),
			'meta_keyword'		=> array(),
		);

		if (isset($this->TAB_FIELDS['product_description']['meta_h1'])) {
			$seo_fields['meta_h1'] = array();
		}

		// Получим поля для сравнения
		$fields_list = array();
		foreach ($seo_fields as $field=>$param) {
			if ($field == 'seo_url') continue;
			$fields_list[] = $field;
		}
		$fields	= implode($fields_list,', ');
		$query = $this->query("SELECT " . $fields . " FROM `" . DB_PREFIX . "category_description` WHERE `category_id` = " . $data['category_id'] . " AND `language_id` = " . $this->LANG_ID);
		if ($query->num_rows) {
			foreach ($fields_list as $field) {
				$data[$field] = $query->row[$field];
			}
		}

		// Сопоставляем значения к тегам
		$tags = array(
			'{cat}'			=> isset($data['name']) 		? $data['name'] 		: '',
			'{cat_id}'		=> isset($data['category_id'])	? $data['category_id'] 	: ''
		);

		// Формируем массив с замененными значениями
		foreach ($seo_fields as $field=>$param) {


			$template = '';
			if ($this->config->get('exchange1c_seo_category_'.$field) == 'template') {
				$template = $this->config->get('exchange1c_seo_category_'.$field.'_template');
			} elseif ($this->config->get('exchange1c_seo_category_'.$field) == 'import') {
				// из свойства которое считалось при обмене
			} else {
				// Пропускаем если ни один способ не задан
				continue;
			}

			// Пустой шаблон, пропускаем генерацию SEO
			if (!$template) {
				$this->log("Пустой шаблон");
				continue;
			}

			if ($this->config->get('exchange1c_seo_category_overwrite') == 'overwrite') {
				// Перезаписывать
				$data[$field] = $this->seoGenerateString($template, $tags, isset($param['trans']));
			} else {
				// Только если поле пустое
				if (empty($data[$field])) {
					$data[$field] = $this->seoGenerateString($template, $tags, isset($param['trans']));
				} else {
					$this->log("Поле '" . $field . "' не пустое", 2);
				}
			}

		}

		if (isset($data['seo_url'])) {
			if ($this->config->get('exchange1c_seo_category_overwrite') == 'overwrite') {
				$this->setSeoURL('category_id', $data['category_id'], $data['seo_url']);
			} else {
				$query = $this->query("SELECT keyword FROM `" . DB_PREFIX . "url_alias` WHERE `query` = 'category_id=" . $data['category_id'] . "'");
				if ($query->num_rows) {
					$data['seo_url'] = $query->row['keyword'];
					if (empty($data['seo_url']))
						$this->setSeoURL('category_id', $data['category_id'], $data['seo_url']);
				} else {
					$this->setSeoURL('category_id', $data['category_id'], $data['seo_url']);
				}
			}
		}
		$this->log("<== Завершено формирование SEO для категории category_id: " . $data['category_id']);

	} // seoGenerateCategory()


	/**
	 * Генерит SEO переменные шаблона для категории
	 */
	private function seoGenerateManufacturer(&$data) {

		// Производители
		$seo_fields = array(
			'seo_url'			=> array('trans' => true),
			'meta_title'		=> array(),
			'meta_description'	=> array(),
			'meta_keyword'		=> array(),
		);


		if (isset($this->TAB_FIELDS['product_description'])) {
			if (isset($this->TAB_FIELDS['product_description']['meta_h1'])) {
				$seo_fields['meta_h1'] = array();
			}
			// Получим поля для сравнения
			$fields_list = array();
			foreach ($seo_fields as $field=>$param) {
				if ($field == 'seo_url') continue;
				$fields_list[] = $field;
			}
			$fields	= implode($fields_list,', ');
			$query = $this->query("SELECT " . $fields . " FROM `" . DB_PREFIX . "manufacturer_description` WHERE `manufacturer_id` = " . $data['manufacturer_id'] . " AND `language_id` = " . $this->LANG_ID);
			if ($query->num_rows) {
				foreach ($fields_list as $field) {
					$data[$field] = $query->row[$field];
				}
			}
		}

		// Сопоставляем значения к тегам
		$tags = array(
			'{brand}'		=> isset($data['name']) 			? $data['name'] 			: '',
			'{brand_id}'	=> isset($data['manufacturer_id'])	? $data['manufacturer_id'] 	: ''
		);

		// Формируем массив с замененными значениями
		foreach ($seo_fields as $field=>$param) {
			$template = '';
			if ($this->config->get('exchange1c_seo_manufacturer_'.$field) == 'template') {
				$template = $this->config->get('exchange1c_seo_manufacturer_'.$field.'_template');
			} elseif ($this->config->get('exchange1c_seo_manufacturer_'.$field) == 'import') {
				// из свойства которое считалось при обмене
			}

			if ($this->config->get('exchange1c_seo_manufacturer_overwrite') == 'overwrite') {
				// Перезаписывать
				$data[$field] = $this->seoGenerateString($template, $tags, isset($param['trans']));
			} else {
				// Только если поле пустое
				if (empty($data[$field])) {
					$data[$field] = $this->seoGenerateString($template, $tags, isset($param['trans']));
				} else {
					$this->log("Поле '" . $field . "' не пустое", 2);
				}
			}

		}

		if (isset($data['seo_url'])) {
			if ($this->config->get('exchange1c_seo_manufacturer_overwrite') == 'overwrite') {
				$this->setSeoURL('manufacturer_id', $data['manufacturer_id'], $data['seo_url']);
			} else {
				$query = $this->query("SELECT keyword FROM `" . DB_PREFIX . "url_alias` WHERE `query` = 'manufacturer_id=" . $data['manufacturer_id'] . "'");
				if ($query->num_rows) {
					$data['seo_url'] = $query->row['keyword'];
					if (empty($data['seo_url']))
						$this->setSeoURL('manufacturer_id', $data['manufacturer_id'], $data['seo_url']);
				} else {
					$this->setSeoURL('manufacturer_id', $data['manufacturer_id'], $data['seo_url']);
				}
			}
		}
		$this->log("> Сформирована SEO для категории manufacturer_id: " . $data['manufacturer_id']);

	} // seoGenerateManufacturer()


	/**
	 * Генерит SEO переменные шаблона для товара
	 */
	public function seoGenerate() {

		$this->log("[!] Генерация SEO в стадии переработки, заработает в версии 1.6.2.b12");

	} // seoGenerate()


	/**
	 * ****************************** ФУНКЦИИ ДЛЯ ЗАГРУЗКИ КАТАЛОГА ******************************
	 */

	/**
	 * Формирует строку запроса для категории
	 */
	private function prepareStrQueryCategory($data, $mode = 'set') {

		$sql = array();

		if (isset($data['top']))
			$sql[] = $mode == 'set' ? "`top` = " .			(int)$data['top']								: "top";
		if (isset($data['column']))
			$sql[] = $mode == 'set' ? "`column` = " .		(int)$data['column']							: "column";
		if (isset($data['sort_order']))
			$sql[] = $mode == 'set' ? "`sort_order` = " . 	(int)$data['sort_order']						: "sort_order";
		if (isset($data['status']))
			$sql[] = $mode == 'set' ? "`status` = " . 		(int)$data['status']							: "status";
		if (isset($data['noindex']))
			$sql[] = $mode == 'set' ? "`noindex` = " . 		(int)$data['noindex']							: "noindex";
		if (isset($data['parent_id']))
			$sql[] = $mode == 'set' ? "`parent_id` = " . 	(int)$data['parent_id']							: "parent_id";
		if (isset($data['image']))
			$sql[] = $mode == 'set' ? "`image` = '" . 		$this->db->escape((string)$data['image']) . "'"	: "image";

		return implode(($mode = 'set' ? ', ' : ' AND '), $sql);

	} //prepareStrQueryCategory()


	/**
	 * Формирует строку запроса для описания категорий и товаров
	 */
	private function prepareStrQueryDescription($data, $mode = 'set') {

		$sql = array();
		if (isset($data['name']))
			$sql[] = $mode == 'set' 	? "`name` = '" .				$this->db->escape($data['name']) . "'"				: "`name`";
		if (isset($data['description']))
			$sql[] = $mode == 'set' 	? "`description` = '" .			$this->db->escape($data['description']) . "'"		: "`description`";
		if (isset($data['meta_title']))
			$sql[] = $mode == 'set' 	? "`meta_title` = '" .			$this->db->escape($data['meta_title']) . "'"		: "`meta_title`";
		if (isset($data['meta_h1']))
			$sql[] = $mode == 'set' 	? "`meta_h1` = '" .				$this->db->escape($data['meta_h1']) . "'"			: "`meta_h1`";
		if (isset($data['meta_description']))
			$sql[] = $mode == 'set' 	? "`meta_description` = '" .	$this->db->escape($data['meta_description']) . "'"	: "`meta_description`";
		if (isset($data['meta_keyword']))
			$sql[] = $mode == 'set' 	? "`meta_keyword` = '" .		$this->db->escape($data['meta_keyword']) . "'"		: "`meta_keyword`";
		if (isset($data['tag']))
			$sql[] = $mode == 'set' 	? "`tag` = '" .					$this->db->escape($data['tag']) . "'"				: "`tag`";

		return implode(($mode = 'set' ? ', ' : ' AND '), $sql);

	} //prepareStrQueryDescription()


	/**
	 * Подготавливает запрос для товара
	 */
	private function prepareQueryProduct($data, $mode = 'set') {

		$sql = array();
		if (isset($data['model']))
	 		$sql[] = $mode == 'set'		? "`model` = '" .				$this->db->escape($data['model']) . "'"				: "`model`";
		if (isset($data['sku']))
	 		$sql[] = $mode == 'set'		? "`sku` = '" .					$this->db->escape($data['sku']) . "'"				: "`sku`";
		if (isset($data['upc']))
	 		$sql[] = $mode == 'set'		? "`upc` = '" .					$this->db->escape($data['upc']) . "'"				: "`upc`";
		if (isset($data['ean']))
	 		$sql[] = $mode == 'set'		? "`ean` = '" .					$this->db->escape($data['ean']) . "'"				: "`ean`";
		if (isset($data['jan']))
	 		$sql[] = $mode == 'set'		? "`jan` = '" .					$this->db->escape($data['jan']) . "'"				: "`jan`";
		if (isset($data['isbn']))
	 		$sql[] = $mode == 'set'		? "`isbn` = '" .				$this->db->escape($data['isbn']) . "'"				: "`isbn`";
		if (isset($data['mpn']))
	 		$sql[] = $mode == 'set'		? "`mpn` = '" .					$this->db->escape($data['mpn']) . "'"				: "`mpn`";
		if (isset($data['location']))
	 		$sql[] = $mode == 'set'		? "`location` = '" .			$this->db->escape($data['location']) . "'"			: "`location`";
		if (isset($data['quantity']))
	 		$sql[] = $mode == 'set'		? "`quantity` = '" .			(float)$data['quantity'] . "'"						: "`quantity`";
		if (isset($data['minimum']))
	 		$sql[] = $mode == 'set'		? "`minimum` = '" .				(float)$data['minimum'] . "'"						: "`minimum`";
		if (isset($data['subtract']))
	 		$sql[] = $mode == 'set'		? "`subtract` = '" .			(int)$data['subtract'] . "'"						: "`subtract`";
		if (isset($data['stock_status_id']))
	 		$sql[] = $mode == 'set'		? "`stock_status_id` = '" .		(int)$data['stock_status_id'] . "'"					: "`stock_status_id`";
		if (isset($data['date_available']))
	 		$sql[] = $mode == 'set'		? "`date_available` = '" .		$this->db->escape($data['date_available']) . "'"	: "`date_available`";
		if (isset($data['manufacturer_id']))
	 		$sql[] = $mode == 'set'		? "`manufacturer_id` = '" .		(int)$data['manufacturer_id'] . "'"					: "`manufacturer_id`";
		if (isset($data['shipping']))
	 		$sql[] = $mode == 'set'		? "`shipping` = '" .			(int)$data['shipping'] . "'"						: "`shipping`";
		if (isset($data['price']))
	 		$sql[] = $mode == 'set'		? "`price` = '" .				(float)$data['price'] . "'"							: "`price`";
		if (isset($data['points']))
	 		$sql[] = $mode == 'set'		? "`points` = '" .				(int)$data['points'] . "'"							: "`points`";
		if (isset($data['length']))
	 		$sql[] = $mode == 'set'		? "`length` = '" .				(float)$data['length'] . "'"						: "`length`";
		if (isset($data['width']))
	 		$sql[] = $mode == 'set'		? "`width` = '" .				(float)$data['width'] . "'"							: "`width`";
		if (isset($data['weight']))
	 		$sql[] = $mode == 'set'		? "`weight` = '" .				(float)$data['weight'] . "'"						: "`weight`";
		if (isset($data['height']))
	 		$sql[] = $mode == 'set'		? "`height` = '" .				(float)$data['height'] . "'"						: "`height`";
		if (isset($data['status']))
	 		$sql[] = $mode == 'set'		? "`status` = '" .				(int)$data['status'] . "'"							: "`status`";
		if (isset($data['noindex']))
	 		$sql[] = $mode == 'set'		? "`noindex` = '" .				(int)$data['noindex'] . "'"							: "`noindex`";
		if (isset($data['tax_class_id']))
	 		$sql[] = $mode == 'set'		? "`tax_class_id` = '" .		(int)$data['tax_class_id'] . "'"					: "`tax_class_id`";
		if (isset($data['sort_order']))
	 		$sql[] = $mode == 'set'		? "`sort_order` = '" .			(int)$data['sort_order'] . "'"						: "`sort_order`";
		if (isset($data['length_class_id']))
	 		$sql[] = $mode == 'set'		? "`length_class_id` = '" .		(int)$data['length_class_id'] . "'"					: "`length_class_id`";
		if (isset($data['weight_class_id']))
	 		$sql[] = $mode == 'set'		? "`weight_class_id` = '" .		(int)$data['weight_class_id'] . "'"					: "`weight_class_id`";

		return implode(($mode = 'set' ? ', ' : ' AND '),$sql);

	} // prepareQueryProduct()



	/**
	 * Формирует строку запроса для описания производителя
	 */
	private function prepareStrQueryManufacturerDescription($data) {

		$sql  = isset($data['description']) 		? ", `description` = '" . $this->db->escape($data['description']) . "'"					: "";
		if (isset($this->TAB_FIELDS['manufacturer_description']['name'])) {
			$sql .= isset($data['name']) 				? ", `name` = '" . $this->db->escape($data['name']) . "'" 							: "";
		}
		$sql .= isset($data['meta_description']) 	? ", `meta_description` = '" . $this->db->escape($data['meta_description']) . "'" 		: "";
		$sql .= isset($data['meta_keyword']) 		? ", `meta_keyword` = '" . $this->db->escape($data['meta_keyword']) . "'"				: "";
		$sql .= isset($data['meta_title']) 			? ", `meta_title` = '" . $this->db->escape($data['meta_title']) . "'"					: "";
		$sql .= isset($data['meta_h1']) 			? ", `meta_h1` = '" . $this->db->escape($data['meta_h1']) . "'" 						: "";

		return $sql;

	} //prepareStrQueryManufacturerDescription()


	/**
	 * Сравнивает запрос с массивом данных и формирует список измененных полей
	 */
	private function compareArrays($query, $data) {

		// Сравниваем значения полей, если есть изменения, формируем поля для запроса
		$upd_fields = array();
		if ($query->num_rows) {
			foreach($query->row as $key => $row) {
				if (!isset($data[$key])) continue;
				if ($row <> $data[$key]) {
					$upd_fields[] = "`" . $key . "` = '" . $this->db->escape($data[$key]) . "'";
					$this->log("[i] Отличается поле '" . $key . "'", 2);
				}
			}
		}

		return implode(', ', $upd_fields);

	} // compareArrays()


	/**
	 * Заполняет родительские категории у продукта
	 */
	public function fillParentsCategories($data) {

		$error = "";

		$this->log('==> fillParentsCategories(data)',2);
		if (!$data['product_id']) {
			$error = "Заполнение родительскими категориями отменено, т.к. не указан product_id!";
		}

		// Подгружаем только один раз
		if (!empty($data['product_categories'])) {
			return $error;
		}

		// Определяем наличие поля main_category
		$main_category = isset($this->TAB_FIELDS['product_to_category']['main_category']);

		// Читаем все категории товара
		$product_categories = array();
		$fields = "`category_id`";

		if ($main_category) {
			$fields .= ", `main_category`";
		}

		$query = $this->query("SELECT " . $fields . " FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = " . $data['product_id']);
		foreach ($query->rows as $row) {
			$product_categories[$row['category_id']] = $main_category ? $row['main_category'] : 0;
		}

		$this->load->model('catalog/product');

		$product_cats_id = $data['product_categories'];
		foreach ($data['product_categories'] as $category_id) {
			$parents_id = $this->findParentsCategories($category_id);
			foreach ($parents_id as $parent_id) {
				$key = array_search($parent_id, $product_cats_id);
				if ($key === false)
					$product_cats_id[] = $parent_id;
			}
		}

		foreach ($product_cats_id as $parent_id) {
			if ($parent_id != 0) {
				if (isset($product_categories[$parent_id])) {
					unset($product_categories[$parent_id]);
				} else {
					$field_main_category = $main_category ? ", `main_category` = " . ($category_id == $parent_id ? 1 : 0) : "";
					$this->query("INSERT INTO `" .DB_PREFIX . "product_to_category` SET `product_id` = " . $data['product_id'] . ", `category_id` = " . $parent_id . $field_main_category);
				}
				$this->log("> Родительская категория, category_id: " . $parent_id, 2);
			}
		}

		$this->log(1,"[i] Заполнены родительские категории для товара product_id: " . $data['product_id']);

		return $error;

	} // fillParentsCategories()


	/**
	 * Ищет все родительские категории
	 *
	 * @param	int
	 * @return	array
	 */
	private function findParentsCategories($category_id) {

		$result = array();
		$query = $this->query("SELECT * FROM `" . DB_PREFIX ."category` WHERE `category_id` = " . $category_id);
		if (isset($query->row['parent_id'])) {
			if ($query->row['parent_id'] <> 0) {
				$result[] = $query->row['parent_id'];
				$result = array_merge($result, $this->findParentsCategories($query->row['parent_id']));
			}
		}
		return $result;

	} // findParentsCategories()


	/**
	 * Устанавливает в какой магазин загружать данные
	 */
	private function setStore($classifier_name) {

		$config_stores = $this->config->get('exchange1c_stores');
		if (!$config_stores) {
			$this->STORE_ID = 0;
			return;
		}

		// Если ничего не заполнено - по умолчанию
		foreach ($config_stores as $key => $config_store) {
			if ($classifier_name == "Классификатор (" . $config_store['name'] . ")") {
				$this->STORE_ID = $config_store['store_id'];
			}
		}

	} // setStore()


	/**
	 * Возвращает id по cml_id
	 */
	private function getCategoryIdBycml_id($cml_id) {

		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "category_to_1c` WHERE `1c_id` = '" . $this->db->escape($cml_id) . "'");
		$category_id = isset($query->row['category_id']) ? $query->row['category_id'] : 0;

		// Проверим существование такого товара
		if ($category_id) {
			$query = $this->query("SELECT `category_id` FROM `" . DB_PREFIX . "category` WHERE `category_id` = " . (int)$category_id);
			if (!$query->num_rows) {

				// Удалим неправильную связь
				$this->deleteLinkCategory($category_id);
				$category_id = 0;
			}
		}
		return $category_id;

	} // getCategoryIdBycml_id()


	/**
	 * Возвращает id по названию и уровню категории
	 */
	private function getCategoryIdByName($name, $parent_id = 0) {

		$query = $this->query("SELECT `c`.`category_id` FROM `" . DB_PREFIX . "category` `c` LEFT JOIN `" . DB_PREFIX. "category_description` `cd` ON (`c`.`category_id` = `cd`.`category_id`) WHERE `cd`.`name` = LOWER('" . $this->db->escape(strtolower($name)) . "') AND `cd`.`language_id` = " . $this->LANG_ID . " AND `c`.`parent_id` = " . $parent_id);
		return $query->num_rows ? $query->row['category_id'] : 0;

	} // getCategoryIdByName()


	/**
	 * Возвращает массив id,name категории по cml_id
	 */
	private function getCategoryBycml_id($cml_id) {

		$query = $this->query("SELECT `c`.`category_id`, `cd`.`name` FROM `" . DB_PREFIX . "category_to_1c` `c` LEFT JOIN `" . DB_PREFIX. "category_description` `cd` ON (`c`.`category_id` = `cd`.`category_id`) WHERE `c`.`1c_id` = '" . $this->db->escape($cml_id) . "' AND `cd`.`language_id` = " . $this->LANG_ID);
		return $query->num_rows ? $query->rows : 0;

	} // getCategoryBycml_id()


	/**
	 * Обновляет описание категории
	 */
	private function updateCategoryDescription($data) {

		// Надо ли обновлять
		$fields = $this->prepareStrQueryDescription($data, 'get');
		if ($fields) {
			$query = $this->query("SELECT " . $fields . " FROM `" . DB_PREFIX . "category_description` `cd` LEFT JOIN `" . DB_PREFIX . "category_to_store` `cs` ON (`cd`.`category_id` = `cs`.`category_id`) WHERE `cd`.`category_id` = " . $data['category_id'] . " AND `cd`.`language_id` = " . $this->LANG_ID . " AND `cs`.`store_id` = " . $this->STORE_ID);
		} else {
			// Нечего даже обновлять
			return false;
		}

		// Сравнивает запрос с массивом данных и формирует список измененных полей
		$fields = $this->compareArrays($query, $data);

		// Если есть расхождения, производим обновление
		if ($fields) {
			$this->query("UPDATE `" . DB_PREFIX . "category_description` SET " . $fields . " WHERE `category_id` = " . $data['category_id'] . " AND `language_id` = " . $this->LANG_ID);
			$this->query("UPDATE `" . DB_PREFIX . "category` SET `date_modified` = '" . $this->NOW . "' WHERE `category_id` = " . $data['category_id']);
			$this->log("> Обновлены поля категории: '" . $fields . "'", 2);
			return true;
		}
		return false;

	} // updateCategoryDescription()


	/**
	 * Добавляет иерархию категории
	 */
	private function addHierarchical($category_id, $data) {

		// MySQL Hierarchical Data Closure Table Pattern
		$level = 0;
		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = " . $data['parent_id'] . " ORDER BY `level` ASC");
		foreach ($query->rows as $result) {
			$this->query("INSERT INTO `" . DB_PREFIX . "category_path` SET `category_id` = " . $category_id . ", `path_id` = " . (int)$result['path_id'] . ", `level` = " . $level);
			$level++;
		}
		$this->query("INSERT INTO `" . DB_PREFIX . "category_path` SET `category_id` = " . $category_id . ", `path_id` = " . $category_id . ", `level` = " . $level);

	} // addHierarchical()


	/**
	 * Обновляет иерархию категории
	 */
	private function updateHierarchical($data) {

		// MySQL Hierarchical Data Closure Table Pattern
		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE `path_id` = " . $data['category_id'] . " ORDER BY `level` ASC");

		if ($query->rows) {
			foreach ($query->rows as $category_path) {
				// Delete the path below the current one
				$this->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = " . (int)$category_path['category_id'] . " AND `level` < " . (int)$category_path['level']);
				$path = array();
				// Get the nodes new parents
				$query = $this->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = " . $data['parent_id'] . " ORDER BY `level` ASC");
				foreach ($query->rows as $result) {
					$path[] = $result['path_id'];
				}
				// Get whats left of the nodes current path
				$query = $this->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = " . $category_path['category_id'] . " ORDER BY `level` ASC");
				foreach ($query->rows as $result) {
					$path[] = $result['path_id'];
				}
				// Combine the paths with a new level
				$level = 0;
				foreach ($path as $path_id) {
					$this->query("REPLACE INTO `" . DB_PREFIX . "category_path` SET `category_id` = " . $category_path['category_id'] . ", `path_id` = " . $path_id . ", `level` = " . $level);

					$level++;
				}
			}
		} else {
			// Delete the path below the current one
			$this->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = " . $data['category_id']);
			// Fix for records with no paths
			$level = 0;
			$query = $this->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE `category_id` = " . $data['parent_id'] . " ORDER BY `level` ASC");
 			foreach ($query->rows as $result) {
				$this->query("INSERT INTO `" . DB_PREFIX . "category_path` SET `category_id` = " . $data['category_id'] . ", `path_id` = " . (int)$result['path_id'] . ", `level` = " . $level);

				$level++;
			}
 			$this->query("REPLACE INTO `" . DB_PREFIX . "category_path` SET `category_id` = " . $data['category_id'] . ", `path_id` = " . $data['category_id'] . ", `level` = " . $level);
		}

		$this->log("<== updateHierarchical()", 2);

	} // updateHierarchical()


	/**
	 * Обновляет категорию
	 */
	private function updateCategory($data) {

		$this->log("==> Начато обновление категории...", 2);

		// Читаем старые данные
		$sql = $this->prepareStrQueryCategory($data, 'get');
		if ($sql) {
			$query = $this->query("SELECT " . $sql . " FROM `" . DB_PREFIX . "category` WHERE `category_id` = " . $data['category_id']);

			// Сравнивает запрос с массивом данных и формирует список измененных полей
			$fields = $this->compareArrays($query, $data);

			if ($fields) {
				$this->query("UPDATE `" . DB_PREFIX . "category` SET " . $fields . ", `date_modified` = '" . $this->NOW . "' WHERE `category_id` = " . $data['category_id']);

				// Запись иерархии категорий если были изменения
				$this->updateHierarchical($data);

				// SEO
		 		$this->seoGenerateCategory($data);
			}
		}

		// Если было обновление описания
		$this->updateCategoryDescription($data);

		// Очистка кэша
		$this->cache->delete('category');

		$this->log("<== Завершено обновление категории", 2);

	} // updateCategory()


	/**
	 * Добавляет связь категории с ТС
	 */
	private function insertCategoryLinkToCML($category_id, $cml_id) {

		$this->query("INSERT INTO `" . DB_PREFIX . "category_to_1c` SET `category_id` = " . (int)$category_id . ", `1c_id` = '" . $this->db->escape($cml_id) . "'");

	}


	/**
	 * Добавляет категорию
	 */
	private function addCategory($data) {

		$this->log("==> Начато добавление категории", 2);
		if ($data == false) return 0;

		if ($this->config->get('exchange1c_status_new_category') == 0){
			$data['status'] = 0;
		}

		$sql = $this->prepareStrQueryCategory($data);
		$this->query("INSERT INTO `" . DB_PREFIX . "category` SET " . $sql . ", `date_modified` = '" . $this->NOW . "', `date_added` = '" . $this->NOW . "'");

		$data['category_id'] = $this->db->getLastId();

		// Формируем SEO
 		$this->seoGenerateCategory($data);

		// Подготовим строку запроса для описания категории
		$fields = $this->prepareStrQueryDescription($data, 'set');

		if ($fields) {
			$query = $this->query("SELECT category_id FROM `" . DB_PREFIX . "category_description` WHERE `category_id` = " . $data['category_id'] . " AND `language_id` = " . $this->LANG_ID);
			if (!$query->num_rows) {
				$this->query("INSERT INTO `" . DB_PREFIX . "category_description` SET `category_id` = " . $data['category_id'] . ", `language_id` = " . $this->LANG_ID . ", " . $fields);
			}
		}

		// Запись иерархии категорий для админки
		$this->addHierarchical($data['category_id'], $data);

		// Магазин
		$this->query("INSERT INTO `" . DB_PREFIX . "category_to_store` SET `category_id` = " . $data['category_id'] . ", `store_id` = " . $this->STORE_ID);

		// Добавим линк
		$this->insertCategoryLinkToCML($data['category_id'], $data['cml_id']);

		// Чистим кэш
		$this->cache->delete('category');

		$this->log("<== Завершено добваление категории: '" . $data['name'] . "', category_id: " . $data['category_id'] . ", Ид: " . $data['cml_id'],2);

		return $data['category_id'];

	} // addCategory()


	/**
	 * Парсит категории из XML
	 */
	private function parseCategories($xml, $parent_id = 0, $classifier) {

		foreach ($xml->Группа as $category){
			if (isset($category->Ид) && isset($category->Наименование) ){

				$data = array();
				$data['cml_id']			= (string)$category->Ид;
				$data['category_id']	= $this->getCategoryIdBycml_id($data['cml_id']);
				$data['parent_id']		= $parent_id;
				$data['status']			= 1;
				$data['sort_order']		= isset($category->Сортировка) ? (int)$category->Сортировка : 0;

				if ($parent_id == 0)
					$data['top']		= 1;

				// Определяем наименование и порядок, сортировка - число до точки, наименование все что после точки
				$split = $this->splitNameStr((string)$category->Наименование, false);
				$data['sort_order']	= $split['order'];
				$data['name']	= $split['name'];

				// Свойства для группы
				if ($category->ЗначенияСвойств && isset($classifier['attributes']))
					$this->parseAttributes($data, $category->ЗначенияСвойств, $classifier['attributes']);

				// Обработка свойств
				if (isset($data['attributes'])) {
					foreach ($data['attributes'] as $attribute) {
						if ($attribute['name'] == 'Картинка') {
							$data['image'] = "catalog/" . $attribute['value'];
						} elseif ($attribute['name'] == 'Сортировка') {
							$data['sort_order'] = $attribute['value'];
						}
					}
				}

				$this->log("Наименование категории: '" . $data['name'] . "', порядок сортировки: " . $data['sort_order'], 2);

				// Если не нашли категорию по Ид, пытаемся найти по имени учитывая id родительской категории
				if (!$data['category_id']) {
					$data['category_id'] = $this->getCategoryIdByName($data['name'], $parent_id);
					// Если нашли, добавляем связь
					if ($data['category_id'])
						$this->insertCategoryLinkToCML($data['category_id'], $data['cml_id']);
				}

				if (!$data['category_id']) {
					if ($this->config->get('exchange1c_create_new_category') == 1) {
						$data['category_id'] = $this->addCategory($data);
					}
				} else {
					$this->updateCategory($data);
				}
			}

			if ($category->Группы) {
				$this->parseCategories($category->Группы, $data['category_id'], $classifier);
			}
		}

	} // parseCategories()


	/**
	 * ******************************************* ОПЦИИ *********************************************
	 */


	/**
	 * Добавляет или получает значение опции по названию
	 */
	private function setOptionValue($option_id, $value, $sort_order, $image = '') {

		$option_value_id = 0;

		// Проверим есть ли такое значение
		$query = $this->query("SELECT `ovd`.`option_value_id`,`ov`.`sort_order` FROM `" . DB_PREFIX . "option_value_description` `ovd` LEFT JOIN `" . DB_PREFIX . "option_value` `ov` ON (`ovd`.`option_value_id` = `ov`.`option_value_id`) WHERE `ovd`.`language_id` = " . $this->LANG_ID . " AND `ovd`.`option_id` = " . $option_id . " AND `ovd`.`name` = '" . $this->db->escape($value) . "'");
		if ($query->num_rows) {
			$option_value_id = $query->row['option_value_id'];

			// если изменилась сортировка
			if ($query->row['sort_order'] <> $sort_order)
				$this->query("UPDATE `" . DB_PREFIX . "option_value` SET `sort_order` = " . $sort_order . " WHERE `option_value_id` = " . $option_value_id);

		}

		if ($option_value_id)
			return $option_value_id;

		$query = $this->query("INSERT INTO `" . DB_PREFIX . "option_value` SET `option_id` = " . $option_id . ", `image` = '" . $this->db->escape($image) . "', `sort_order` = " . $sort_order);
		$option_value_id = $this->db->getLastId();

		if ($option_value_id)
 			$query = $this->query("INSERT INTO `" . DB_PREFIX . "option_value_description` SET `option_id` = " . $option_id . ", `option_value_id` = " . $option_value_id . ", `language_id` = " . $this->LANG_ID . ", `name` = '" . $this->db->escape($value) . "'");

		return $option_value_id;

	} // setOptionValue()


	/**
	 * Добавляет или получает значение опциию по названию
	 * НЕ ИСПОЛЬЗУЕТСЯ
	 */
	private function OFFsetOptionValues($option_id, $values) {

		$this->log($values, 2);

		foreach ($values as $key => $value) {

			$option_value_id = 0;
  			$query = $this->query("SELECT `ov`.`option_value_id` FROM `" . DB_PREFIX . "option_value` `ov` LEFT JOIN `" . DB_PREFIX . "option_value_description` `ovd` ON (`ov`.`option_value_id` = `ovd`.`option_value_id`) WHERE `ov`.`option_id` = " . $option_id . " AND `ovd`.`language_id` = '" . $this->LANG_ID . "' AND `ovd`.`name` = '" . $this->db->escape($value['name']) . "'");

			if ($query->num_rows) {
				$option_value_id = $query->row['option_value_id'];
			}
			if (!$option_value_id) {
				$query = $this->query("INSERT INTO `" . DB_PREFIX . "option_value` SET `option_id` = " . $option_id . ", `image` = '" . $this->db->escape($value['image']) . "', `sort_order` = " . $value['sort_order']);
				$option_value_id = $this->db->getLastId();
 				$query = $this->query("INSERT INTO `" . DB_PREFIX . "option_value_description` SET `option_id` = " . $option_id . ", `option_value_id` = '" . $option_value_id . "', `language_id` = " . $this->LANG_ID . ", `name` = '" . $this->db->escape($value['name']) . "'");
 			}
			$values[$key]['option_value_id'] = $option_value_id;
		}

		return $values;

	} // setOptionValues()


	/**
	 * Установка опции
	 */
	private function setOption($name, $type = 'select', $sort_order = 0) {

		$query = $this->query("SELECT `o`.`option_id`, `o`.`type`, `o`.`sort_order` FROM `" . DB_PREFIX . "option` `o` LEFT JOIN `" . DB_PREFIX . "option_description` `od` ON (`o`.`option_id` = `od`.`option_id`) WHERE `od`.`name` = '" . $this->db->escape($name) . "' AND `od`.`language_id` = " . $this->LANG_ID);
        if ($query->num_rows) {

			$this->log($query, 2);
			$option_id = $query->row['option_id'];

			$fields = array();
        	if ($query->row['type'] <> $type) {
        		$fields[] = "`type` = '" . $type . "'";
        	}

        	if ($query->row['sort_order'] <> $sort_order) {
        		$fields[] = "`sort_order` = " . (int)$sort_order;
        	}

        	$fields = implode(', ', $fields);
        	if ($fields) {
				$this->query("UPDATE `" . DB_PREFIX . "option` SET " . $fields . " WHERE `option_id` = " . $option_id);
        	}

        } else {
			// Если опции нет, добавляем
			$option_id = $this->addOption($name, $type);
        }

		return $option_id;
	} // setOption()


	/**
	 * **************************************** ОПЦИИ ТОВАРА ******************************************
	 */


	/**
	 * Добавляет или находит опцию в товаре и возвращает ID
     * $data['product_id'], $option_id, $option_name
	 */
	private function setProductOption($product_id, $option_id, $option_name, $required = 1) {

		$query = $this->query("SELECT `product_option_id` FROM `" . DB_PREFIX . "product_option` WHERE `product_id` = " . $product_id . " AND `option_id` = " . $option_id . " AND `value` = '" . $this->db->escape($option_name) . "'");
		$product_option_id = $query->num_rows ? $query->row['product_option_id'] : 0;

 		if (!$product_option_id) {
			$this->query("INSERT INTO `" . DB_PREFIX . "product_option` SET `product_id` = " . $product_id . ", `option_id` = " . $option_id . ", `value` = '" . $this->db->escape($option_name) . "', `required` = " . $required);
			$product_option_id = $this->db->getLastId();
			$this->log("> Добавлена опция в товар, product_option_id: " . $product_option_id, 2);
		}

		return $product_option_id;

	} // setProductOption()


	/**
	 * Добавляет опцию в товар
	 */
	private function addProductOptionValue($feature, $option, $data) {

        if (isset($feature['product_quantity'])) {
        	if (count($feature['product_quantity']))
        		$quantity = $feature['quantities']['quantity'];
        } elseif (isset($feature['quantity'])) {
        	$quantity = $feature['quantity'];
		} else {
        	$quantity = 0;
        }

		$this->query("INSERT INTO `" . DB_PREFIX . "product_option_value` SET `product_option_id` = " . $option['product_option_id'] . ", `product_id` = " . $data['product_id'] . ", `option_value_id` = " . $option['option_value_id'] . ", `option_id` = " . $option['option_id'] . ", quantity = '" . $quantity . "', `subtract` = " . $option['subtract']);
 		$product_option_value_id = $this->db->getLastId();

		$this->log("> Добавлено значение опции в товар, product_option_value_id: " . $product_option_value_id, 2);

       	return $product_option_value_id;

	} // addProductOptionValue()


	/**
	 * Обновляет опцию в товар
	 */
	private function updateProductOptionValue($product_option_value_id, $quantity, $price_prefix = "", $price = 0) {

		$query = $this->query("SELECT `quantity`,`price_prefix`,`price` `" . DB_PREFIX . "product_option_value` WHERE `product_option_value_id` = " . $product_option_value_id);

		$sql = "";
		if ($query->row['quantity'] <> $quantity) {
			$sql .= " `quantity` = " . $quantity;
		}
		if ($query->row['price_prefix'] <> $price_prefix && $query->row['price'] <> $price) {
			$sql .= ($sql ? "," : "") . " `price_prefix` = " . $price_prefix . ", `price` = " . $price;
		}

		if ($sql) {
			$this->query("UPDATE `" . DB_PREFIX . "product_option_value` SET " . $sql . " WHERE `product_option_value_id` = ". $product_option_value_id);
			$this->log("Обновлено значение опции в товаре", 2);
			return true;
		}

       	return false;

	} // updateProductOptionValue()


	/**
	 * Устанавливаем опцию в товар
	 */
	private function setProductOptionValue($feature, $option, $data) {

		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "product_option_value` WHERE `product_option_id` = " . $option['product_option_id'] . " AND `product_id` = " . $data['product_id'] . " AND `option_id` = " . $option['option_id'] . " AND option_value_id = " . $option['option_value_id']);
		$product_option_value = $query->num_rows ? $query->row : 0;

		if (empty($product_option_value)){
			$product_option_value_id = $this->addProductOptionValue($feature, $option, $data);
		} else {
			$product_option_value_id = $product_option_value['product_option_value_id'];

			// В режиме загрузки характеристик - характеристика, записываем остаток и разницу цен в опции
			// но в этом случае использователь несколько цен нельзя, так как разница записанная в опцию,
			// будет распространятся и на остальные цены
			if ($this->config->get('exchange1c_product_options_mode') == 'feature') {
				// Определим разницу в цене
				//$this->updateProductOptionValue($product_option_value_id, $feature['quantity'], $price_prefix, $price);
			}
		}

       	return $product_option_value_id;
	} // addProductOptionValue()


	/**
	 * ************************************ ФУНКЦИИ ДЛЯ РАБОТЫ С ХАРАКТЕРИСТИКАМИ *************************************
	 */

	/**
	 * Ищет, проверяет, добавляет значение характеристики товара
	 */
	private function setProductFeatureValue($product_feature_id, $product_id, $product_option_id, $product_option_value_id) {

		$query = $this->query("SELECT `product_feature_value_id` FROM `" . DB_PREFIX . "product_feature_value` WHERE `product_feature_id` = " . $product_feature_id . " AND `product_option_value_id` = " . $product_option_value_id);
		if ($query->num_rows) {
			return $query->row['product_feature_value_id'];
		}

       	// Добавим значение
		$query = $this->query("INSERT INTO `" . DB_PREFIX . "product_feature_value` SET `product_feature_id` = " . $product_feature_id . ", `product_id` = " . $product_id . ", `product_option_id` = " . $product_option_id . ", `product_option_value_id` = " . $product_option_value_id);
		$product_feature_value_id = $this->db->getLastId();

		$this->log("> Добавлено значение характеристики, product_feature_value_id: " . $product_feature_value_id, 2);
		return $product_feature_value_id;

	} // setProductFeatureValue()


	/**
	 * Получить минимальную цену из всех характеристик
	 * Пока не работает, просто сохранил алгоритм в эту функцию
	 * НЕ ИСПОЛЬЗУЕТСЯ
	 */
	private function OFFgetMinPrice(&$data) {
		// Найдем минимальную цену и установим корректировку цен для опций каждой группы покупателей
		$min_prices = array();
		foreach ($data['features'] as $feature_cml_id => $feature) {
			// Цены
			foreach($feature['prices'] as $feature_price) {
				if (isset($min_prices[$feature_price['customer_group_id']])) {
					$min_prices[$feature_price['customer_group_id']] = array(
						'price'		=> min($min_prices[$feature_price['customer_group_id']]['price'], $feature_price['price']),
						'quantity'	=> $feature_price['quantity'],
						'priority'	=> $feature_price['priority'],
						'unit_id'	=> $feature_price['unit_id']
					);
				} else {
					$min_prices[$feature_price['customer_group_id']] = array(
						'price'		=> $feature_price['price'],
						'quantity'	=> $feature_price['quantity'],
						'priority'	=> $feature_price['priority'],
						'unit_id'	=> $feature_price['unit_id']
					);
				}
			}

		}
		$data['min_prices'] = $min_prices;
	} // getMinPrice()


	/**
	 * Создает или возвращает характеристику по Ид
	 */
	private function setProductFeatures(&$data) {

		if (!isset($data['features'])) return "Нет характеристик";

		$this->log("==> Начало записи характеристик...", 2);

		$this->log($data, 2);

		$min_price_value = 0;

		foreach ($data['features'] as $feature_cml_id => $feature) {
			// СВЯЗЬ ХАРАКТЕРИСТИКИ С ТОРГОВОЙ СИСТЕМОЙ
			// Ищем характеристику по Ид
			$query = $this->query("SELECT * FROM `" . DB_PREFIX . "product_feature` WHERE `1c_id` = '" . $this->db->escape($feature_cml_id) . "'");

			if ($query->num_rows) {
				$product_feature_id = $query->row['product_feature_id'];
				$data['features'][$feature_cml_id]['product_feature_id'] = $product_feature_id;

				// Сравнивает запрос с массивом данных и формирует список измененных полей
				$fields = $this->compareArrays($query, $feature);

				if ($fields) {
					$this->query("UPDATE `" . DB_PREFIX . "product_feature` SET " . $fields . " WHERE `product_feature_id` = " . $product_feature_id);
					$this->log("> Характеристика обновлена, product_feature_id: " . $product_feature_id, 2);
				}

	       	} else {
	       		// Добавляем
	       		$sql = isset($feature['name'])	? ", `name` = '"	. $this->db->escape($feature['name']) 	. "'" : "";
	       		$sql .= isset($feature['sku'])	? ", `sku` = '"		. $this->db->escape($feature['sku']) 	. "'" : "";
	       		$sql .= isset($feature['ean'])	? ", `ean` = '"		. $feature['ean'] . "'" : "";
				$this->query("INSERT INTO `" . DB_PREFIX . "product_feature` SET `1c_id` = '" . $this->db->escape($feature_cml_id) . "'" . $sql);

				$product_feature_id = $this->db->getLastId();
				$data['features'][$feature_cml_id]['product_feature_id'] = $product_feature_id;
				$this->log("Характеристика добавлена, product_feature_id: " . $product_feature_id, 2);
	       	}

	       	// ЦЕНЫ
	       	if (isset($feature['prices'])) {
	       		foreach ($feature['prices'] as $cml_id => $price_data) {
	       			$this->setProductPrice($price_data, $data['product_id'], $product_feature_id);

					// основная минимальная цена товара
					if (!isset($data['prices'])) {
						$data['prices'] = array();
						$data['prices'][$cml_id] = array(
							'default'	=> $price_data['default'],
							'price'		=> 0
						);
					} elseif (!isset($data['prices'][$cml_id])) {
						$data['prices'][$cml_id] = array(
							'default'	=> $price_data['default'],
							'price'		=> 0
						);
					}

					$this->log($price_data, 2);

					if ($data['prices'][$cml_id]['price'] == 0) {
	       				$data['prices'][$cml_id]['price'] = $price_data['price'];
	       			} else {
	       				$data['prices'][$cml_id]['price'] = min($data['prices'][$cml_id]['price'], $price_data['price']);
					}
	       		}
	       	}

	       	// ОСТАТКИ
	       	if (isset($feature['quantities'])) {
	       		$this->log("> Запись остатков характеристики");

	       		foreach ($feature['quantities'] as $warehouse_id => $quantity) {
	       			$product_filter = array(
	       				'product_id'			=> $data['product_id'],
	       				'warehouse_id'			=> $warehouse_id,
	       				'product_feature_id'	=> $product_feature_id
					);
					// так как не указана какая единица измерения, подразумевается - базовая
	       			$product_quantity_id = $this->setProductQuantity($product_filter, $quantity);

					// которые есть остатки удаляем из массива
					if (isset($product_old_quantities[$product_quantity_id])) {
						unset($product_old_quantities[$product_quantity_id]);
					}
	       		}
	       	}

	       	// ЕДИНИЦЫ ИЗМЕРЕНИЯ
	       	if (isset($feature['unit'])) {
	       		$this->log("> Запись единиц измерений характеристики");
	       		$this->setProductUnits($data['product_id'], $feature['unit'], $product_feature_id);
	       	}

		}
       	$this->log($data['prices'], 2);


        $this->log("<== Запись характеристик завершена успешно", 2);
		return "";

	} // setProductFeatures()


	/**
	 * Находит характеристику товара
	 */
	private function getProductFeatureId($feature_cml_id) {

		// Ищем характеристику по Ид
		$query = $this->query("SELECT `product_feature_id` FROM `" . DB_PREFIX . "product_feature` WHERE `1c_id` = '" . $this->db->escape($feature_cml_id) . "'");

		if ($query->num_rows) {
			$this->log("> Найдена характеристика, product_feature_id: " . $query->row['product_feature_id'], 2);
			return $query->row['product_feature_id'];
		}

		return 0;

	} // getProductFeatureId()


	/**
	 * Обрабатывает опции характеристики
	 * и записывает их в товар
	 */
	private function setProductFeaturesOptions(&$data) {
		$this->log("==> Установка опций для характеристик", 2);

		// Читаем старые опции товара, сравниваем, лишние удаляем
		$old_options = array();
		$query = $this->query("SELECT `product_option_id` FROM `" . DB_PREFIX . "product_option` WHERE `product_id` = " . $data['product_id']);
		foreach ($query->rows as $option) {
			$old_options[] = $option['product_option_id'];
		}

		// Читаем старые значения опциий товара
		$old_values = array();
		$query = $this->query("SELECT `product_option_value_id` FROM `" . DB_PREFIX . "product_option_value` WHERE `product_id` = " . $data['product_id']);
		foreach ($query->rows as $value) {
			$old_values[] = $value['product_option_value_id'];
		}

		// Читаем старые значения характеристики текущего товара
		$old_features_values = array();
		$query = $this->query("SELECT `product_feature_value_id` FROM `" . DB_PREFIX . "product_feature_value` WHERE `product_id` = " . $data['product_id']);
		foreach ($query->rows as $value) {
			$old_features_values[] = $value['product_feature_value_id'];
		}

		foreach ($data['features'] as $feature) {
			if (!isset($feature['options'])) {
				$this->log("[i] У характеристики нет опций, читаем следующую", 2);
				continue;
			}

			// Массив с опциями, если нет опций, то массив будет пустой
			foreach ($feature['options'] as $option) {

				// Запишем опции в товар
				$option['product_option_id'] = $this->setProductOption($data['product_id'], $option['option_id'], $option['name']);
				$key = array_search($option['product_option_id'], $old_options);
				if ($key !== false) {
					unset($old_options[$key]);
				}

				// Запишем значения опции в товар
				$product_option_value_id = $this->setProductOptionValue($feature, $option, $data);
				$key = array_search($product_option_value_id, $old_values);
				if ($key !== false) {
					unset($old_values[$key]);
				}

				// Установим значение в характеристике
				$product_feature_value_id = $this->setProductFeatureValue($feature['product_feature_id'], $data['product_id'], $option['product_option_id'], $product_option_value_id);
				$key = array_search($product_feature_value_id, $old_features_values);
				if ($key !== false) {
					unset($old_features_values[$key]);
				}

			}
		}

		// Удалим старые неиспользуемые опции из товара
		//foreach ($old_options as $option) {
		//	$query = $this->query("DELETE FROM `" . DB_PREFIX . "product_option` WHERE `product_option_id` = " . $option);
		//}

		// Удалим старые неиспользуемые значения опции из товара
		//foreach ($old_values as $value) {
		//	$this->query("DELETE FROM `" . DB_PREFIX . "product_option_value` WHERE `product_option_value_id` = " . $value);
		//}

		// Удалим старые неиспользуемые значения опции из товара
		//foreach ($old_features_values as $value) {
		//	$this->query("DELETE FROM `" . DB_PREFIX . "product_feature_value` WHERE `product_feature_value_id` = " . $value);
		//}

		$this->log("<== Опции характеристик успешно обработаны", 2);
		return "";
	}  // setProductFeaturesOptions()


	/**
	 * Удаление характеристик и опций у товара
	 */
	private function deleteProductFeatures($product_id) {

		$this->log("==> deleteFeatures()",2);
		// Удалим старые характеристики
		$query = $this->query("DELETE FROM `" . DB_PREFIX . "product_feature` WHERE `product_id` = " . $product_id);
		// Удалим старые значения характеристики
		$query = $this->query("DELETE FROM `" . DB_PREFIX . "product_feature_value` WHERE `product_id` = " . $product_id);

	} // deleteProductFeatures()


	/**
	 * **************************************** ФУНКЦИИ ДЛЯ РАБОТЫ С ТОВАРОМ ******************************************
	 */


	/**
	 * Добавляет товар в базу
	 */
	private function addProduct(&$data) {

		$this->log("==> Начало добавления товара в базу", 2);

		if ($this->config->get('exchange1c_status_new_product') == 0){
			$data['status'] = 0;
		}

		// Подготовим список полей по которым есть данные
		$fields = $this->prepareQueryProduct($data);
		if ($fields) {
			$this->query("INSERT INTO `" . DB_PREFIX . "product` SET " . $fields . ", `date_added` = '" . $this->NOW . "', `date_modified` = '" . $this->NOW . "'");
			$data['product_id'] = $this->db->getLastId();
		} else {
			// Если нет данных - выходим
			$this->log("Товар не был добавлен в базу", 2);
			return "";
		}

		// Полное наименование из 1С в товар
		if ($this->config->get('exchange1c_import_product_name') == 'fullname' && !empty($data['full_name'])) {
			if ($data['full_name'])
				$data['name'] = $data['full_name'];
		}

		// описание (пока только для одного языка)
		$fields = $this->prepareStrQueryDescription($data);
		if ($fields) {
			$this->query("INSERT INTO `" . DB_PREFIX . "product_description` SET `product_id` = " . $data['product_id'] . ", `language_id` = " . $this->LANG_ID . ", " . $fields);
		}

		// Категории товара
		if (isset($data['product_categories'])) {
			$this->setProductCategories($data['product_id'], $data['product_categories']);
		}

		// Связь с 1С
		$this->query("INSERT INTO `" . DB_PREFIX . "product_to_1c` SET `product_id` = " . $data['product_id'] . ", `1c_id` = '" . $this->db->escape($data['product_cml_id']) . "'");

		// если есть характеристики
		if (isset($data['features'])) {

			// наименование характеристики, связи
			$error = $this->setProductFeatures($data);
			if ($error) return $error;

			// Формируем список опций из всех характеристик
			$error = $this->setProductFeaturesOptions($data);
			if ($error) return $error;
		}

		// Устанавливаем магазин
		$this->setProductToShop($data['product_id']);

		// Очистим кэш товаров
		$this->cache->delete('product');

		$this->log("Товар успешно добавлен, product_id: " . $data['product_id'],2);

		return "";

	} // addProduct()


	/**
	 * Обновляет описание товара в базе для одного языка
	 */
	private function updateProductDescription($data) {

		$fields = $this->prepareStrQueryDescription($data, 'get');
		if ($fields) {
			$query = $this->query("SELECT " . $fields . " FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = " . $data['product_id'] . " AND `language_id` = " . $this->LANG_ID);
		} else {
			// Нечего обновлять даже
			return false;
		}

		// Сравнивает запрос с массивом данных и формирует список измененных полей
		$fields = $this->compareArrays($query, $data);

		// Если есть расхождения, производим обновление
		if ($fields) {
			$this->query("UPDATE `" . DB_PREFIX . "product_description` SET " . $fields . " WHERE `product_id` = " . $data['product_id'] .  " AND `language_id` = " . $this->LANG_ID);
			$this->log("[i] Описание товара обновлено, обновлены поля: '" . $fields . "'",2);
			return true;
		}

		return false;

	} // updateProductDescription()


	/**
	 * Устанавливает товар в магазин который производится загрузка
	 * Если това в этом магазине не найден, то добавляем
	 */
	private function setProductToShop($product_id) {

		$query = $this->query("SELECT `store_id`  FROM `" . DB_PREFIX . "product_to_store` WHERE `product_id` = " . $product_id . " AND `store_id` = " . $this->STORE_ID);
		if (!$query->num_rows) {
			$this->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET `product_id` = " . $product_id . ", `store_id` = " . $this->STORE_ID);
			$this->log("> Добавлена привязка товара к магазину store_id: " . $this->STORE_ID,2);
		}

	} // setProductToShop()


	/**
	 * Устанавливает единицы измерения товара, в том числе и базовую
	 */
	private function setProductUnits($product_id, $units, $product_feature_id = 0) {

	    $this->log("==> Начата запись единиц измерений товара", 2);

		// читаем все единицы товара
		$old_units = array();
		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "product_unit` WHERE `product_id` = " . $product_id . " AND `product_feature_id` = " . $product_feature_id);
		foreach ($query->rows as $unit) {
			$old_units[$unit['product_unit_id']] = array(
				'product_feature_id'	=> $unit['product_feature_id'],
				'unit_id'				=> $unit['unit_id'],
				'ratio'					=> $unit['ratio']
			);
		}
		//$this->log($old_units,2);

		// ищем была ли такая единица
		$product_unit_id = 0;
		foreach ($old_units as $old_product_unit_id => $old_unit) {
			if ($unit['unit_id'] == $old_unit['unit_id'] && $unit['ratio'] == $old_unit['ratio'] && $unit['product_feature_id'] == $product_feature_id) {
				unset($old_units[$old_product_unit_id]);
				$product_unit_id = $old_product_unit_id;
			}
		}

		if (!$product_unit_id) {
			// добавляем
			$this->log($units,2);
			$this->query("INSERT INTO `" . DB_PREFIX . "product_unit` SET `product_id` = " . $product_id . ", `product_feature_id` = " . $product_feature_id . ", `unit_id` = " . $units['unit_id'] . ", `ratio` = " . $units['ratio']);
		}

		// удаляем лишние единицы
		foreach ($old_units as $product_unit_id => $old_unit) {
			$this->query("DELETE FROM `" . DB_PREFIX . "product_unit` WHERE `product_unit_id` = " . $product_unit_id);
		}

		$this->log("<== Завершена запись единиц измерений товара", 2);
	} // setProductUnits()


	/**
	 * Записывает в товар категории
	 */
	private function setProductCategories($product_id, $product_categories) {
		$this->log("==> Начата запись категорий товара", 2);

		// если в CMS ведется учет главной категории
		$main_category = isset($this->TAB_FIELDS['product_to_category']['main_category']) ? 1 : 0;

		//$this->log($this->TAB_FIELDS['product_to_category'], 2);

		$old_categories = array();
		if ($main_category) {
			$query = $this->query("SELECT `category_id`,`main_category`  FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = " . $product_id);
		} else {
			$query = $this->query("SELECT `category_id`  FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = " . $product_id);
		}

		foreach ($query->rows as $category) {
			$old_categories[$category['category_id']] = $main_category;
		}

		foreach ($product_categories as $key => $category_id) {
		if (isset($old_categories[$category_id])) {
				// Если есть ничего не делаем, отмечаем что такая группа есть
				unset($old_categories[$category_id]);
			} else {
				// Значит надо добавить, возможно группу удалили или изменили
				if ($main_category) {
					$this->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET `product_id` = " . $product_id . ", `category_id` = " . $category_id . ($key == 0 ? ", `main_category` = 1" : ", `main_category` = 0"));
				} else {
					$this->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET `product_id` = " . $product_id . ", `category_id` = " . $category_id);
				}
			}
		}

		// а те которые не указаны в файле, удаляем
		foreach ($old_categories as $category_id => $main_category) {
			$this->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE `product_id` = " . $product_id . " AND `category_id` = " . $category_id);
		}
		$this->log("<== Завершена запись категорий товара", 2);
	} // setProductCategories()


	/**
	 * Обновляет товар в базе
	 */
	private function updateProduct(&$data) {

		$this->log("==> Начало обновления товара в базе...",2);
		$this->log($data,2);

		$update = false;

		// Обнуляем остаток только у тех товаров что загружаются
		if ($this->config->get('exchange1c_flush_quantity') == 1) {
			$data['quantity'] = 0;
		}

		// ФИЛЬТР ОБНОВЛЕНИЯ
		if ($this->config->get('exchange1c_import_product_name') == 'disable') {
			unset($data['name']);
			$this->log("[i] Обновление названия отключено",2);
		}
		if ($this->config->get('exchange1c_import_categories') <> 1) {
			unset($data['product_categories']);
			$this->log("[i] Обновление категорий отключено",2);
		}
		if ($this->config->get('exchange1c_import_product_description') <> 1) {
			unset($data['description']);
			$this->log("[i] Обновление описаний товаров отключено",2);
		}

		if ($this->config->get('exchange1c_import_product_manufacturer') <> 1) {
			unset($data['manufacturer_id']);
			$this->log("[i] Обновление производителя в товаре отключено",2);
		}
		// КОНЕЦ ФИЛЬТРА

		// если есть характеристики
		if (isset($data['features'])) {

			// наименование характеристики, связи
			$error = $this->setProductFeatures($data);
			if ($error) return $error;

			// Формируем список опций из всех характеристик
			$error = $this->setProductFeaturesOptions($data);
			if ($error) return $error;
		}

		// общий остаток товара
		if (isset($data['quantity'])) {
			$error = $this->setQuantity($data['product_id'], $data['quantity']);
			if ($error) return $error;
		}

		// цены без характеристик
		if (isset($data['prices'])) {
			$this->log($data['prices'], 2);
			$error = $this->setPrice($data['product_id'], $data['prices']);
			if ($error) return $error;
		}

		if ($this->config->get('exchange1c_product_disable_if_zero') == 1) {
			if ($data['quantity'] <= 0) {
				$data['status'] = 0;
				$this->log("> Товар отключен, так как остаток нулевой");
			}
		}

		// Полное наименование из 1С в товар
		if ($this->config->get('exchange1c_import_product_name') == 'fullname' && isset($data['full_name'])) {
			if ($data['full_name'])
				$data['name'] = $data['full_name'];
		}

		// Читаем только те данные, которые получены из файла
		$fields = $this->prepareQueryProduct($data, 'get');
		if ($fields) {
			$query = $this->query("SELECT " . $fields . "  FROM `" . DB_PREFIX . "product` WHERE `product_id` = " . $data['product_id']);
		}

		// SEO формируем только из offers
		//$this->seoGenerateProduct($data);

		// Сравнивает запрос с массивом данных и формирует список измененных полей
		$fields = $this->compareArrays($query, $data);

		// Если есть что обновлять
		if ($fields) {
			$this->query("UPDATE `" . DB_PREFIX . "product` SET " . $fields . ", `date_modified` = '" . $this->NOW . "' WHERE `product_id` = " . $data['product_id']);
			$update = true;
		}

		// Единицы товара
		if (isset($data['unit'])) {
			$this->setProductUnits($data['product_id'], $data['unit']);
		}

		// Обновляем описание товара
		if ($this->updateProductDescription($data))
			$update = true;

		// Категории товара
		if (isset($data['product_categories'])) {
			$this->setProductCategories($data['product_id'], $data['product_categories']);
		}

		// Устанавливаем магазин
		$this->setProductToShop($data['product_id']);

		// Очистим кэш товаров
		$this->cache->delete('product');

		$this->log("[i] Обновление товара завершено успешно!", 2);
		return "";

	} // updateProduct()


	/**
	 * Получает product_id по артикулу
	 */
	private function getProductBySKU($sku) {

		$query = $this->query("SELECT `product_id` FROM `" . DB_PREFIX . "product` WHERE `sku` = '" . $this->db->escape($sku) . "'");
		if ($query->num_rows) {
	 		$this->log("> Найден product_id: " . $query->row['product_id'] . " по артикулу '" . $sku . "'",2);
			return $query->row['product_id'];
		}
		$this->log("> Не найден товар по артикулу '" . $sku . "'",2);
		return 0;

	} // getProductBySKU()


	/**
	 * Получает product_id по наименованию товара
	 */
	private function getProductByName($name) {

		$query = $this->query("SELECT `pd`.`product_id` FROM `" . DB_PREFIX . "product` `p` LEFT JOIN `" . DB_PREFIX . "product_description` `pd` ON (`p`.`product_id` = `pd`.`product_id`) WHERE `name` = LOWER('" . $this->db->escape(strtolower($name)) . "')");
		if ($query->num_rows) {
	 		$this->log("> Найден product_id: " . $query->row['product_id'] . " по названию '" . $name . "'",2);
			return $query->row['product_id'];
		}
		$this->log("> Не найден товар по названию '" . $name . "'",2);
		return 0;

	} // getProductByName()


	/**
	 * Получает product_id по наименованию товара
	 */
	private function getProductByEAN($ean) {

		$this->log("==> getProductByEAN()",2);
		$query = $this->query("SELECT `product_id` FROM `" . DB_PREFIX . "product` WHERE `ean` = '" . $ean . "'");
		if ($query->num_rows) {
	 		$this->log("> Найден product_id: " . $query->row['product_id'] . " по штрихкоду '" . $ean . "'",2);
			return $query->row['product_id'];
		}
		$this->log("> Не найден товар по штрихкоду '" . $ean . "'",2);
		return 0;

	} // getProductByEAN()


	/**
	 * Обновление или добавление товара
	 * вызывается при обработке каталога
	 */
 	private function setProduct(&$data) {
		$this->log("==> Начата запись товара...", 2);

		// Проверка на ошибки
		if (empty($data)) {
			return "Нет входящих данных";
		}

		if (!$data['product_cml_id']) {
			return "Не задан Ид товара из торговой системы";
		}

 		// Ищем товар...
 		$data['product_id'] = $this->getProductIdByCML($data['product_cml_id']);
 		if (!$data['product_id']) {

 			// Синхронизация по артикулу
 			if ($this->config->get('exchange1c_synchronize_new_product_by') == 'sku') {
 				$this->log("[i] Товар новый, ищем по артикулу: '" . $data['sku'] . "'", 2);

				 if (empty($data['sku'])) {
 					return "При синхронизации по артикулу, артикул не должен быть пустым! Проверьте товар " . $data['name'];
 				}

				$data['product_id'] = $this->getProductBySKU($data['sku']);

 			 // Синхронизация по наименованию
			 } elseif ($this->config->get('exchange1c_synchronize_new_product_by') == 'name' && !empty($data['name'])) {
 				$this->log("[i] Товар новый, ищем по наименованию: '" . $data['name'] . "'", 2);

 				if (empty($data['name'])) {
 					return "При синхронизации по наименованию, наименование не должно быть пустым! Проверьте товар по Ид '" . $data['product_cml_id'] . "'";
 				}

 				$data['product_id'] = $this->getProductByName($data['name']);

			// Синхронизация по штрихкоду
 			} elseif ($this->config->get('exchange1c_synchronize_new_product_by') == 'ean') {
 				$this->log("[i] Товар новый, ищем по штрихкоду: " . $data['ean'], 2);

 				if (empty($data['ean'])) {
 					return "При синхронизации по штрихкоду, штрихкод не должен быть пустым! Проверьте товар " . $data['name'];
 				}

				$data['product_id'] = $this->getProductByEan($data['name']);
 			}

			// Если нашли, запишем связь
 			if ($data['product_id'])
				$this->query("INSERT INTO `" . DB_PREFIX . "product_to_1c` SET `product_id` = '" . (int)$data['product_id'] . "', `1c_id` = '" . $this->db->escape($data['product_cml_id']) . "'");

 		}
 		// Можно добавить поиск по наименованию или другим полям...

 		// Если не найден товар...
 		if (!$data['product_id']) {
 			if ($this->config->get('exchange1c_create_new_product') == 1) {
 				$error = $this->addProduct($data);
 				if ($error) return $error;
 			} else {
				$this->log("[!] Новый товар запрещено создавать!");
				return "";
 			}
 		} else {
 			$error = $this->updateProduct($data);
 			if ($error) return $error;
 		}

		// Записываем атрибуты в товар
		if (isset($data['product_attributes'])) {
			$this->setProductAttributes($data);
		}

		// Заполнение родительских категорий в товаре
		if ($this->config->get('exchange1c_fill_parent_cats') == 1) {
			$error = $this->fillParentsCategories($data);
			if ($error) return $error;
		}

		// Картинки
		if (isset($data['images'])) {
			$this->setProductImages($data);
		}

 		//$this->log($data,2);
 		$this->log("<== Завершена запись товара", 2);

 		return "";
 	} // setProduct()


	/**
	 * Читает реквизиты товара из XML в массив
	 */
	private function parseRequisite($xml, $data) {
		$this->log("==> Начато чтение реквизитов...", 2);

		$this->log("Всего реквизитов: " . sizeof($xml->ЗначениеРеквизита), 2);

		foreach ($xml->ЗначениеРеквизита as $requisite){
			$name 	= (string)$requisite->Наименование;
			$value 	= $requisite->Значение;

			switch ($name){
				case 'Вес':
					$data['weight'] = $value ? (float)str_replace(',','.',$value) : 0;
					$this->log("> Реквизит: " . $name. " => weight",2);
				break;
				case 'ТипНоменклатуры':
					$data['item_type'] = $value ? (string)$value : '';
					$this->log("> Реквизит: " . $name. " => item_type",2);
				break;
				case 'ВидНоменклатуры':
					$data['item_view'] = $value ? (string)$value : '';
					$this->log("> Реквизит: " . $name. " => item_view",2);
				break;
				case 'ОписаниеВФорматеHTML':
					if ($value) {
						$data['description'] =  (string)$value;
						$this->log("> Реквизит: " . $name. " => description (HTML format)",2);
					}
				break;
				case 'Полное наименование':
					$data['full_name'] = $value ? htmlspecialchars((string)$value) : '';
					$this->log("> Реквизит: " . $name. " => full_name",2);
				break;
				default:
					$this->log("[!] Неиспользуемый реквизит: " . $name. " = " . (string)$value,2);
			}
		}

		$this->log("<== Завершено чтение реквизитов", 2);
		return $data;

	} // parseRequisite()


	/**
	 * Получает путь к картинке и накладывает водяные знаки
	 */
	private function applyWatermark($filename, $wm_filename) {
		$this->log("==> applyWatermark()",2);

		$wm_fullname = DIR_IMAGE . $wm_filename;
		$fullname = DIR_IMAGE . $filename;

		if (is_file($wm_fullname) && is_file($fullname)) {

			// Получим расширение файла
			$info = pathinfo($filename);
			$extension = $info['extension'];

			// Создаем объект картинка из водяного знака и получаем информацию о картинке
			$image = new Image($fullname);
			if (version_compare($this->config->get('exchange1c_CMS_version'), '2.0.3.1', '>')) {
				$image->watermark(new Image($wm_fullname));
			} else  {
				$image->watermark($wm_fullname);
			}

			// Формируем название для файла с наложенным водяным знаком
			$new_image = utf8_substr($filename, 0, utf8_strrpos($filename, '.')) . '_wm.' . $extension;

			// Сохраняем картинку с водяным знаком
			$image->save(DIR_IMAGE . $new_image);

			$this->log("> Файл с водяным знаком " . $new_image);
			$this->log("[i] Удален старый файл: " . $filename,2);
			return $new_image;
		}
		else {
			return $filename;
		}
	} // applyWatermark()


	/**
	 * Определяет что за файл и принимает дальнейшее действие
	 */
	private function setFile($filename, $product_id) {
		$this->log("==> setFile()",2);
		$info = pathinfo($filename);
		if (isset($info['extension'])) {

			// Если расширение txt - грузим в описание
			if ($info['extension'] == "txt") {
				$description = file_get_contents($filename);
				// если не в кодировке UTF-8, переводим
				if (!mb_check_encoding($description, 'UTF-8')) {
					$description = nl2br(htmlspecialchars(iconv('windows-1251', 'utf-8', $description)));
				}
				// обновляем только описание
				$this->updateProductDescription(array('description'	=> $description, 'product_id' => $product_id));
				$this->log("> Добавлено описание товара из файла",1);
				$this->log("> Файл описания  " . $filename, 2);
				return 1;
			}
		}
		return 0;
	} // setFile())


	/**
	 * Добавляет или обновляет картинки в товаре
	 */
	private function setProductImages($data) {

		// Нужно ли обновлять картинки товара
		if (!$this->config->get('exchange1c_import_images') == 1) {
			$this->log("[i] Обновление картинок отключено!");
			return true;
		}

		$this->log("Записываем картинки в товар...",2);

		$watermark = $this->config->get('exchange1c_watermark');
		$index = 0;

		// Прочитаем все старые картинки
		$old_images = array();
		$query = $this->query("SELECT `product_image_id`,`image` FROM `" . DB_PREFIX . "product_image` WHERE `product_id` = " . $data['product_id']);
		foreach ($query->rows as $image) {
			$old_images[$image['product_image_id']] = $image['image'];
		}

		// Основная картинка
		$query = $this->query("SELECT `image` FROM `" . DB_PREFIX . "product` WHERE `product_id` = " . $data['product_id']);
		if ($query->num_rows)
			$old_images[0] = $query->row['image'];

		$this->log("old images: ", 2);
		$this->log($old_images, 2);

		foreach ($data['images'] as $image) {

			$image = (string)$image;
			$this->log("Картинка: " . $image, 2);

			if (empty($image)) {
				continue;
			}

			$full_image = DIR_IMAGE . $image;

			if (file_exists($full_image)) {
				// Является ли файл картинкой, прочитаем свойства картинки
				$image_info = getimagesize($full_image);
				if ($image_info == NULL) {
					if (!$this->setFile($full_image, $data['product_id'])) {
						$this->log("Файл '" . $image . "' не является картинкой");
					}
					continue;
				}
				// если надо наложить водяные знаки, проверим накладывали уже их ранее, т.е. имеется ли такой файл
				if (!empty($watermark)) {
					// Файл с водяными знаками имеет название /path/image_wm.ext
					$path_parts = pathinfo($image);
					$new_image = $path_parts['dirname'] . "/" . $path_parts['filename'] . "_wm." . $path_parts['extension'];
					if (!file_exists(DIR_IMAGE . $new_image)) {
						// Если нет файла, накладываем водяные знаки
						$new_image = $this->applyWatermark($image, $watermark);
						$this->log("[i] Создана картинка с водяными знаками: " . $new_image, 2);
					}
//					// Удаляем оригинал
//					$this->log("[i] Удаляем оригинальный файл: " . $image, 2);
//					unlink($full_image);

				} else {
					// Не надо накладывать водяные знаки
					$new_image = $image;
				}


			} else {
				// если картинки нет подставляем эту
				$new_image = 'no_image.png';
			}

			// основная картинка
			if ($index == 0) {
				if ($old_images[0] <> $new_image) {
					// Надо обновить
					$this->query("UPDATE `" . DB_PREFIX . "product` SET `image` = '" . $this->db->escape($new_image) . "' WHERE `product_id` = " . $data['product_id']);
					$this->log("> Картинка основная: '" . $new_image . "'", 2);
				}
				// Удалять картинку не нужно
				$product_image_id = array_search($new_image, $old_images);
				if ($product_image_id !== false) {
					$this->log("Найден product_image_id = " . $product_image_id, 2);
					unset($old_images[$product_image_id]);
				}
			} else {
				// Установим картинку в товар, т.е. если нет - добавим, если есть возвратим product_image_id
				$product_image_id = array_search($new_image, $old_images);
				if ($product_image_id !== false) {
					$this->log("Найден product_image_id = " . $product_image_id, 2);
					unset($old_images[$product_image_id]);
				} else {
					// Нет картинки такой
					$this->query("INSERT INTO `" . DB_PREFIX . "product_image` SET `product_id` = " . $data['product_id'] . ", `image` = '" . $this->db->escape($new_image) . "', `sort_order` = " . $index);
					$this->log("> Картинка дополнительная: '" . $new_image . "'", 2);
				}
			}

			$index ++;
		}

		// Удалим старые неиспользованные картинки
		foreach ($old_images as $product_image_id => $image) {
			$this->query("DELETE FROM `" . DB_PREFIX . "product_image` WHERE `product_image_id` = " . $product_image_id);

			if (is_file(DIR_IMAGE . $image)) {
				// Также удалим файл с диска
				unlink(DIR_IMAGE . $image);
				$this->log("[i] Удален файл: " . DIR_IMAGE . $image, 2);
			}
		}

	} // setProductImages()


	/**
	 * Читает картинки из XML в массив
	 */
	private function parseImages($xml, &$data) {

		if (!$xml) {
			return "Нет картинок в XML";
		}

		$data['images'] = array();
		foreach ($xml as $image) {

			$image = (string)$image;

			if (empty($image)) {
				continue;
			}

			$this->log("> Картинка: " . $image, 2);
			$data['images'][] = $image;

		}

		return "";
	} // parseImages()


	/**
	 * Возвращает id группы для свойств
	 */
	private function setAttributeGroup($name) {

   		$this->log("> Запись группы свойства: '" . $name . "'",2);

		$query = $this->query("SELECT `attribute_group_id` FROM `" . DB_PREFIX . "attribute_group_description` WHERE `name` = '" . $this->db->escape($name) . "'");
		if ($query->rows) {
			return $query->row['attribute_group_id'];
		}

		// Добавляем группу
		$this->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET `sort_order` = 1");

		$attribute_group_id = $this->db->getLastId();
		$this->query("INSERT INTO `" . DB_PREFIX . "attribute_group_description` SET `attribute_group_id` = " . $attribute_group_id . ", `language_id` = " . $this->LANG_ID . ", `name` = '" . $this->db->escape($name) . "'");

		return $attribute_group_id;

	} // setAttributeGroup()


	/**
	 * Возвращает id атрибута из базы
	 */
	private function setAttribute($cml_id, $attribute_group_id, $name, $sort_order) {

		// Ищем свойства по 1С Ид
		$attribute_id = 0;
		$query = $this->query("SELECT `attribute_id` FROM `" . DB_PREFIX . "attribute_to_1c` WHERE `1c_id` = '" . $this->db->escape($cml_id) . "'");
		if ($query->num_rows) {
			$attribute_id = $query->row['attribute_id'];
		}

		if (!$attribute_id) {
			// Попытаемся найти по наименованию
			$query = $this->query("SELECT `a`.`attribute_id` FROM `" . DB_PREFIX . "attribute` `a` LEFT JOIN `" . DB_PREFIX . "attribute_description` `ad` ON (`a`.`attribute_id` = `ad`.`attribute_id`) WHERE `ad`.`language_id` = " . $this->LANG_ID . " AND `ad`.`name` LIKE '" . $this->db->escape($name) . "' AND `a`.`attribute_group_id` = " . $attribute_group_id);
			if ($query->num_rows) {
				$attribute_id = $query->row['attribute_id'];
			}
		}

		// Обновление
		if ($attribute_id) {
			$query = $this->query("SELECT `a`.`attribute_group_id`,`ad`.`name` FROM `" . DB_PREFIX . "attribute` `a` LEFT JOIN `" . DB_PREFIX . "attribute_description` `ad` ON (`a`.`attribute_id` = `ad`.`attribute_id`) WHERE `ad`.`language_id` = " . $this->LANG_ID . " AND `a`.`attribute_id` = " . $attribute_id);
			if ($query->num_rows) {
				// Изменилась группа свойства
				if ($query->row['attribute_group_id'] <> $attribute_group_id) {
					$this->query("UPDATE `" . DB_PREFIX . "attribute` SET `attribute_group_id` = " . (int)$attribute_group_id . " WHERE `attribute_id` = " . $attribute_id);
				}
				// Изменилось имя
				if ($query->row['name'] <> $name) {
					$this->query("UPDATE `" . DB_PREFIX . "attribute_description` SET `name` = '" . $this->db->escape($name) . "' WHERE `attribute_id` = " . $attribute_id . " AND `language_id` = " . $this->LANG_ID);
				}
			}

			$this->log("> Обновлен атрибут: attribute_id: " . $attribute_id, 2);
			return $attribute_id;
		}

		// Добавим в базу характеристику
		$this->query("INSERT INTO `" . DB_PREFIX . "attribute` SET `attribute_group_id` = " . $attribute_group_id . ", `sort_order` = " . $sort_order);
		$attribute_id = $this->db->getLastId();
		$this->query("INSERT INTO `" . DB_PREFIX . "attribute_description` SET `attribute_id` = " . $attribute_id . ", `language_id` = " . $this->LANG_ID . ", `name` = '" . $this->db->escape($name) . "'");

		// Добавляем ссылку для 1С Ид
		$this->query("INSERT INTO `" .  DB_PREFIX . "attribute_to_1c` SET `attribute_id` = " . $attribute_id . ", `1c_id` = '" . $this->db->escape($cml_id) . "'");

		$this->log("> Добавлен атрибут: attribute_id: " . $attribute_id, 2);

		return $attribute_id;

	} // setAttribute()


	/**
	 * Загружает значения атрибута (Свойства из 1С)
	 */
	private function parseAttributesValues($xml) {

		$data = array();
		if (!$xml) {
			return $data;
		}

		if (isset($xml->ВариантыЗначений)) {
			if (isset($xml->ВариантыЗначений->Справочник)) {
				foreach ($xml->ВариантыЗначений->Справочник as $item) {
					$value = trim(htmlspecialchars((string)$item->Значение, 2));
					$data[(string)$item->ИдЗначения] = $value;
					$this->log("XML> Значение: " . $value,2);
				}
			}
		}

		return $data;
	} // parseAttributesValues()


	/**
	 * Загружает атрибуты (Свойства из 1С) в классификаторе
	 */
	private function parseClassifierAttributes($xml) {

		$this->log("==> Начало чтения всех свойств в атрибуты из классификатора", 2);

		$data = array();
		$sort_order = 0;
		if ($xml->Свойство) {
			$properties = $xml->Свойство;
		} else {
			$properties = $xml->СвойствоНоменклатуры;
		}

		foreach ($properties as $property) {
			$cml_id		= (string)$property->Ид;
			$name 		= trim((string)$property->Наименование);

			// Название группы свойств по умолчанию (в дальнейшем сделать определение в настройках)
			$group_name = "Свойства";

			// Определим название группы в название свойства в круглых скобках в конце названия
			$name_split = $this->splitNameStr($name);
			//$this->log($name_split, 2);
			if ($name_split['option']) {
				$group_name = $name_split['option'];
				$this->log("> Группа свойства: " . $group_name, 2);
			}
			$name = $name_split['name'];
			// Установим группу для свойств
			$attribute_group_id = $this->setAttributeGroup($group_name);

			if ($property->ДляПредложений) {
				// Свойства для характеристик скорее всего
				if ((string)$property->ДляПредложений == 'true') {
					$this->log("> Свойство '" . $name . "' только для предложений, в атрибуты не будет добавлено", 2);
					continue;
				}
			}

			switch ($name) {
				case 'Производитель':
					$values = $this->parseAttributesValues($property);
					foreach ($values as $manufacturer_cml_id => $value) {
						$this->setManufacturer($value, $manufacturer_cml_id);
					}
				//break;
				case 'Изготовитель':
					$values = $this->parseAttributesValues($property);
					foreach ($values as $manufacturer_cml_id => $value) {
						$this->setManufacturer($value, $manufacturer_cml_id);
					}
				//break;
				default:
					$data[$cml_id] = array(
						'name'			=> $name,
						'attribute_id'	=> $this->setAttribute($cml_id, $attribute_group_id, $name, $sort_order)
					);
					$values = $this->parseAttributesValues($property);
					if ($values) {
						$data[$cml_id]['values'] = $values;
					}
					$sort_order ++;
					$this->log("> Свойство: '" . $name . "'", 2);
			}

		}
		$this->log("<== Свойств загружено: " . sizeof($properties), 2);

		return $data;
	} // parseClassifierAttributes()


	/**
	 * Читает свойства товара  записывает их в массив
	 */
	private function parseAttributes(&$data, $xml, $attributes) {

		$this->log("==> Начато чтение свойств",2);

		$product_attributes = array();

		foreach ($xml->ЗначенияСвойства as $property) {

			// Ид объекта в 1С
			$cml_id = (string)$property->Ид;

			// Загружаем только те что в классификаторе
			if (!isset($attributes[$cml_id])) {
				$this->log("[i] Свойство не было загружено в классификаторе, Ид: " . $cml_id,2);
				continue;
			}

			$name 	= trim($attributes[$cml_id]['name']);
			$value 	= trim((string)$property->Значение);

			if ($value) {
				if ($attributes[$cml_id]) {
					// агрегатный тип
					if (isset($attributes[$cml_id]['values'][$value])) {
						$value = trim($attributes[$cml_id]['values'][$value]);
					}
				}
			}

			// Пропускаем с пустыми значениями
			if (empty($value)) {
				$this->log("[i] У свойства нет значения, не будет обработано", 2);
				continue;
			}

			switch ($name) {
				case 'Производитель':
					// Устанавливаем производителя из свойства только если он не был еще загружен в секции Товар
					if (!isset($data['manufacturer_id'])) {
						$data['manufacturer_id'] = $this->setManufacturer($value);
						$this->log("> Производитель (из свойства): '" . $value . "', id: " . $data['manufacturer_id'],2);
					}
				break;
				case 'Изготовитель':
					// Устанавливаем производителя из свойства только если он не был еще загружен в секции Товар
					if (!isset($data['manufacturer_id'])) {
						$data['manufacturer_id'] = $this->setManufacturer($value);
						$this->log("> Производитель (из свойства): '" . $value . "', id: " . $data['manufacturer_id'],2);
					}
				break;
				case 'Вес':
					$data['weight'] = round((float)str_replace(',','.',$value), 3);
					$this->log("> Свойство Вес => weight = ".$data['weight'],2);
				break;
				case 'Ширина':
					$data['width'] = round((float)str_replace(',','.',$value), 2);
					$this->log("> Свойство Ширина => width",2);
				break;
				case 'Высота':
					$data['height'] = round((float)str_replace(',','.',$value), 2);
					$this->log("> Свойство Высота => height",2);
				break;
				case 'Длина':
					$data['length'] = round((float)str_replace(',','.',$value), 2);
					$this->log("> Свойство Длина => length",2);
				break;
				case 'Модель':
					$data['model'] = (string)$value;
					$this->log("> Свойство Модель => model",2);
				break;
				case 'Артикул':
					$data['sku'] = (string)$value;
					$this->log("> Свойство Артикул => sku",2);
				break;
				default:
					$product_attributes[$attributes[$cml_id]['attribute_id']] = array(
						'name'			=> $name,
						'value'			=> $value,
						'cml_id'		=> $cml_id,
						'attribute_id'	=> $attributes[$cml_id]['attribute_id']
					);
					$this->log("> Свойство: '" . $name . "' = '" . $value . "'",2);
			}
		}
		$data['attributes'] = $product_attributes;

		$this->log($data, 2);

		$this->log("<== Завершено чтение свойств",2);

	} // parseProductAttributes()


	/**
	 * Устанавливает свойства в товар из массива
	 */
	private function setProductAttributes($data) {
		$this->log("==> Начата запись атрибутов", 2);

		// Проверяем
		$product_attributes = array();
		$query = $this->query("SELECT `attribute_id`,`text` FROM `" . DB_PREFIX . "product_attribute` WHERE `product_id` = " . $data['product_id'] . " AND `language_id` = " . $this->LANG_ID);
		foreach ($query->rows as $attribute) {
			$product_attributes[$attribute['attribute_id']] = $attribute['text'];
		}

		foreach ($data['attributes'] as $property) {
			// Проверим есть ли такой атрибут
			//$this->log("[i] Поиск значения: '" . $property['value'] . "'",2);

			if (isset($product_attributes[$property['attribute_id']])) {

				// Проверим значение
				if ($product_attributes[$property['attribute_id']] != $property['value'])
					$this->query("UPDATE `" . DB_PREFIX . "product_attribute` SET `text` = '" . $this->db->escape($property['value']) . "' WHERE `product_id` = " . $data['product_id'] . " AND `attribute_id` = " . $property['attribute_id'] . " AND `language_id` = " . $this->LANG_ID);

				unset($product_attributes[$property['attribute_id']]);
			} else {
				// Добавим в товар
				$this->query("INSERT INTO `" . DB_PREFIX . "product_attribute` SET `product_id` = " . $data['product_id'] . ", `attribute_id` = " . $property['attribute_id'] . ", `language_id` = " . $this->LANG_ID . ", `text` = '" .  $this->db->escape($property['value']) . "'");
				$this->log("Свойство '" . $this->db->escape($property['name']) . "' = '" . $this->db->escape($property['value']) . "' записано в товар id: " . $data['product_id'],2);
			}
		}

		// Удалим неиспользованные
		//foreach ($product_attributes as $attribute_id => $attribute) {
		//	$this->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE `product_id` = " . $data['product_id'] . " AND `language_id` = " . $this->LANG_ID . " AND `attribute_id` = " . $attribute_id);
		//}

		$this->log("<== Завершена запись атрибутов", 2);
	} // setProductAttributes()


	/**
	 * Обновляем производителя в базе данных
	 */
	private function updateManufacturer($data) {
		$this->log("==> Начато обновление производителя()",2);

		$query = $this->query("SELECT `name` FROM `" . DB_PREFIX . "manufacturer` WHERE `manufacturer_id` = " . $data['manufacturer_id']);
		if ($query->row['name'] <> $data['name']) {
			// Обновляем
			$sql  = " `name` = '" . $this->db->escape($data['name']) . "'";
			$sql .= isset($data['noindex']) ? ", `noindex` = " . $data['noindex'] : "";
			$this->query("UPDATE `" . DB_PREFIX . "manufacturer` SET " . $sql . " WHERE `manufacturer_id` = " . $data['manufacturer_id']);
		}

		if ($this->TAB_FIELDS['manufacturer_description']) {

	        $this->seoGenerateManufacturer($data);
			$query = $this->query("SELECT `name`,`description`,`meta_title`,`meta_description`,`meta_keyword` FROM `" . DB_PREFIX . "manufacturer_description` WHERE `manufacturer_id` = " . $data['manufacturer_id'] . " AND `language_id` = " . $this->LANG_ID);

			// Сравнивает запрос с массивом данных и формирует список измененных полей
			$fields = $this->compareArrays($query, $data);

			if ($fields) {
				$this->query("UPDATE `" . DB_PREFIX . "manufacturer_description` SET " . $fields . " WHERE `manufacturer_id` = " . $data['manufacturer_id'] . " AND `language_id` = " . $this->LANG_ID);
				$this->log("Обновлено описание производителя '" . $data['name'] . "'",2);
			}

		}

		$this->log("<== Завершено обновление производителя", 2);
		return true;
	} // updateManufacturer()


	/**
	 * Добавляем производителя
	 */
	private function addManufacturer(&$manufacturer_data) {
		$this->log("==> Начато добавление производителя", 2);

		$sql 	 = " `name` = '" . $this->db->escape($manufacturer_data['name']) . "'";
		$sql 	.= isset($manufacturer_data['sort_order']) 			? ", `sort_order` = " . $manufacturer_data['sort_order']					: "";
		$sql 	.= isset($manufacturer_data['image']) 				? ", `image` = '" . $this->db->escape($manufacturer_data['image']) . "'" 	: ", `image` = ''";
		$sql 	.= isset($manufacturer_data['noindex']) 			? ", `noindex` = " . $manufacturer_data['noindex'] 							: "";
		$query = $this->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET" . $sql);

		$manufacturer_data['manufacturer_id'] = $this->db->getLastId();
        $this->seoGenerateManufacturer($manufacturer_data);

		if ($this->TAB_FIELDS['manufacturer_description']) {
			$sql = $this->prepareStrQueryManufacturerDescription($manufacturer_data);
			if ($sql) {
				$this->query("INSERT INTO `" . DB_PREFIX . "manufacturer_description` SET `manufacturer_id` = " . $manufacturer_data['manufacturer_id'] . ", `language_id` = " . $this->LANG_ID . $sql);
			}
		}

		// добавляем связь
		if (isset($manufacturer_data['cml_id'])) {
			$this->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_1c` SET `1c_id` = '" . $this->db->escape($manufacturer_data['cml_id']) . "', `manufacturer_id` = " . $manufacturer_data['manufacturer_id']);
		}

		$this->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_store` SET `manufacturer_id` = " . $manufacturer_data['manufacturer_id'] . ", `store_id` = " . $this->STORE_ID);
 		$this->log("Добавлен производитель '" . $manufacturer_data['name'] . "', id: " . $manufacturer_data['manufacturer_id']);
		$this->log("<== Завершено добавление производителя",2);
	} // addManufacturer()


	/**
	 * Устанавливаем производителя
	 */
	private function setManufacturer($name, $cml_id='') {
		$this->log("==> setManufacturer(name = ".$name.", cml_id = ".$cml_id.")",2);

		$manufacturer_data = array();
		$manufacturer_data['name']			= (string)$name;
		$manufacturer_data['description'] 	= 'Производитель ' . $manufacturer_data['name'];
		$manufacturer_data['sort_order']	= 1;
		$manufacturer_data['cml_id']		= (string)$cml_id;

		//if ($this->existField("manufacturer", "noindex")) {
		//	$manufacturer_data['noindex'] = 1;	// значение по умолчанию
		//}
		if (isset($this->FIELDS['manufacturer']['noindex'])) {
			$manufacturer_data['noindex'] = 1;	// значение по умолчанию
		}

		if ($cml_id) {
			// Поиск (производителя) изготовителя по 1C Ид
			$query = $this->query("SELECT mc.manufacturer_id FROM `" . DB_PREFIX . "manufacturer_to_1c` mc LEFT JOIN `" . DB_PREFIX . "manufacturer_to_store` ms ON (mc.manufacturer_id = ms.manufacturer_id) WHERE mc.1c_id = '" . $this->db->escape($manufacturer_data['cml_id']) . "' AND ms.store_id = " . $this->STORE_ID);
		} else {
			// Поиск по имени
			$query = $this->query("SELECT m.manufacturer_id FROM `" . DB_PREFIX . "manufacturer` m LEFT JOIN `" . DB_PREFIX . "manufacturer_to_store` ms ON (m.manufacturer_id = ms.manufacturer_id) WHERE m.name LIKE '" . $this->db->escape($manufacturer_data['name']) . "' AND ms.store_id = " . $this->STORE_ID);
		}

		// Если есть таблица manufacturer_description тогда нужно условие
		// AND language_id = '" . $this->LANG_ID . "'

		if ($query->num_rows) {
			$manufacturer_data['manufacturer_id'] = $query->row['manufacturer_id'];
			$this->log("Найден manufacturer_id: " . $manufacturer_data['manufacturer_id'], 2);
		}

		if (!isset($manufacturer_data['manufacturer_id'])) {
			// Создаем
			$this->addManufacturer($manufacturer_data);
		} else {
			// Обновляем
			$this->updateManufacturer($manufacturer_data);
		}

		$this->log("Производитель: '" . $manufacturer_data['name'] . "'",2);

		$this->log("<== setManufacturer()",2);
		return $manufacturer_data['manufacturer_id'];

	} // setManufacturer()


	/**
	 * Обрабатывает единицу измерения товара
	 */
	private function parseProductUnit($xml) {

		$data = array();

		if (!$xml) {
			return $data;
		}

		$this->log("==> Начато чтение единицы измерения товара");

		// Коэффициент пересчета от базовой единицы
		if (isset($xml->Пересчет)) {
			$data['ratio']	= (float)$xml->Пересчет->Коэффициент;
		} else {
			$data['ratio']	= 1;
		}

		// Если единица не назначена, устанавливается по умолчанию штука
		$data['code'] = isset($xml['Код']) ? (string)$xml['Код'] : "796";
		$data['unit_id'] = $this->getUnitId($data['code']);

		if (isset($xml['НаименованиеПолное'])) {
			$data['full_name'] = htmlspecialchars((string)$xml['НаименованиеПолное']);
		}
		if (isset($xml['МеждународноеСокращение'])) {
			$data['code_eng'] = (string)$xml['МеждународноеСокращение'];
		}

		if (!isset($data['name'])) {
			// Если имя не задаоно в xml получим из таблицы
			$query = $this->query("SELECT `rus_name1` FROM `" . DB_PREFIX . "unit` WHERE `number_code` = '" . $data['code'] . "'");
			if ($query->num_rows) {
				$data['name'] = $query->row['rus_name1'];
			}
		}

		$this->log($data);
		$this->log("<== Завершено чтение единица измерения товара");
		return $data;
	} // parseProductUnit()


	/**
	 * Обрабатывает единицы измерения в классификаторе XML 2.09
	 */
	private function parseUnits($xml) {

		$this->log("==> Читаем единицы измерения в классификаторе (XML 2.09)",2);
		$old_inits = array();

		// Прочитаем старые соответствия единиц измерения
		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "unit_to_1c`");
		if ($query->num_rows) {
			$old_inits[$query->row['unit_id']] = $query->row['cml_id'];
		}

		foreach ($xml->ЕдиницаИзмерения as $unit) {
			// Сопоставляет Ид с id единицей измерения CMS
			$cml_id		= (string)$unit->Ид;
			$delete		= isset($unit->ПометкаУдаления) ? (string)$unit->ПометкаУдаления : "false";
			$name		= (string)$unit->НаименованиеКраткое;
			$code		= (string)$unit->Код;
			$fullname	= (string)$unit->НаименованиеПолное;
			$code_eng	= (string)$unit->МеждународноеСокращение;

			$key = array_search($cml_id, $old_inits);
			if (false !== $key) {
				unset($old_inits[$key]);
			} else {
				$unit_id = $this->getUnitId($code);
				$this->query("INSERT INTO `" . DB_PREFIX . "unit_to_1c` SET `cml_id` = '" . $this->db->escape($cml_id) . "', `unit_id` = " . $unit_id);
			}
		}

		// удаляем неиспользуемые
		foreach ($old_inits as $key => $old_init) {
			$this->query("DELETE FROM `" . DB_PREFIX . "unit_to_1c` WHERE `unit_id` = " . (int)$key);
		}

		$this->log("==> Единицы измерения в классификаторе прочитаны (XML 2.09)",2);

	} // parseUnits()


	/**
	 * Удаляет старые неиспользуемые картинки
	 * Сканирует все файлы в папке import_files и ищет где они указаны в товаре, иначе удаляет файл
	 */
	public function cleanOldImages($folder, &$num) {

		$dir = dir(DIR_IMAGE . $folder);
		while ($file = $dir->read()) {

			if ($file == '.' || $file == '..') {
				continue;
			}

			$path = $folder . $file;

			if (file_exists(DIR_IMAGE . $path)) {
				if (is_file(DIR_IMAGE . $path)) {

					// это файл, проверим его причастность к товару
					$query = $this->query("SELECT `product_id`,`image` FROM `" . DB_PREFIX . "product` WHERE `image` LIKE '". $path . "'");
					if ($query->num_rows) {
						$this->log("файл: '" . $path . "' принадлежит товару: " . $query->row['product_id'], 2);
						continue;
					} else {
						$this->log("Не найден в базе, нужно удалить файл: " . $path, 2);
						$num++;
					}

				} elseif (is_dir(DIR_IMAGE . $path)) {
					$this->cleanOldImages($path . '/', $num);
					// Попытка удалить папку, если она не пустая, то произойдет удаление
					$result = @rmdir(DIR_IMAGE . $path);
					if ($result) {
						$this->log("Удалена пустая папка: " . $path, 2);
					}
					continue;
				}
			}

		}
	}


	/**
	 * Обрабатывает товары из раздела <Товары> в XML
	 */
	private function parseProducts($xml, $classifier, &$error) {

    	$result = array(
    		'products'	=> 0,
    		'images'	=> 0
		);

		if (!$xml->Товар) return $result;

		$this->log("==> Начало чтения товаров из XML...", 2);

		// В некоторых CMS имеется поле для синхронизаци, например с Yandex
		if (isset($this->TAB_FIELDS['product']['noindex'])) {
			$noindex = 1;
		}

		// По умолчанию статус при отсутствии на складах
		$default_stock_status = $this->config->get('exchange1c_default_stock_status');

		$data = array();

		foreach ($xml->Товар as $product){

			// Получаем Ид товара и характеристики
			$cml_id = explode("#", (string)$product->Ид);
			$product_cml_id = $cml_id[0];
			$feature_cml_id = isset($cml_id[1]) ? $cml_id[1] : '';

			// ОПРЕДЕЛЯЕМ К КАКОМУ ТОВАРУ ОТНОСИТСЯ ПРЕДЛОЖЕНИЕ
			if (isset($data['product_cml_id'])) {
				if ($data['product_cml_id'] == $product_cml_id) {
					$data['new_product'] = 0;
				} else {
					$this->log("Это новый товар, предыдущий записываем в базу", 2);
					// Добавляем или обновляем товар в базе
					$this->setProduct($data, $error);
					if ($error)	return $result;

					$data = array();

				}
			}

			if (empty($data)) {
				// Новый товар, записываются поля не касающиеся характеристик
				$data = array(
					'new_product'		=> 1
					,'product_cml_id' 	=> $cml_id[0]
					,'mpn'				=> $cml_id[0]
					,'name'				=> htmlspecialchars((string)$product->Наименование)
					,'status'			=> 1
					,'length_class_id'	=> $this->config->get('config_length_class_id')
					,'weight_class_id'	=> $this->config->get('config_weight_class_id')
				);

				$this->log(">>>>> ТОВАР: '" . $data['name'] . "' <<<<<");

				if ($product->Код) {
					$data['code']			= htmlspecialchars((string)$product->Код);
				}

				if ($product->Артикул) {
					$data['sku']			= htmlspecialchars((string)$product->Артикул);
				}

				if ($product->Модель) {
					$data['model']			= htmlspecialchars((string)$product->Модель);
				}

				if (!isset($data['model'])) {
					$data['model']		= isset($data['sku']) ? $data['sku'] : $cml_id[0];
				}

				if (isset($noindex)) {
					$data['noindex']		= $noindex; // В некоторых версиях
				}

				if ($product->ПолноеНаименование) {
					$data['full_name']		= htmlspecialchars((string)$product->ПолноеНаименование);
					$this->log("Полное наименование товара: " . $data['full_name'], 2);
				}

				// описание в текстовом формате, нужна опция если описание в формате HTML
				if ($product->Описание)	{
					$description = (string)$product->Описание;
					$data['description']	= $this->config->get('exchange1c_description_html') == 1 ? $description : nl2br(htmlspecialchars($description));
				}

				// Реквизиты (разные версии CML)
				if ($product->ЗначениеРеквизита)
					$data = $this->parseRequisite($product, $data);

				// Реквизиты (разные версии CML)
				if ($product->ЗначенияРеквизитов)
					$data = $this->parseRequisite($product->ЗначенияРеквизитов, $data);

				// Тип номенклатуры читается из реквизитов
				// Если фильтр по типу номенклатуры заполнен, то загружаем указанные там типы
				$exchange1c_parse_only_types_item = $this->config->get('exchange1c_parse_only_types_item');
				if (isset($data['item_type']) && (!empty($exchange1c_parse_only_types_item))) {
					if (mb_stripos($exchange1c_parse_only_types_item, $data['item_type']) === false) {
					 	continue;
					}
				}

				// Категории
				$data['product_categories']	= array();
				if ($product->Группы) {
					foreach ($product->Группы->Ид as $category_cml_id) {
						$category_id = $this->getCategoryIdBycml_id((string)$category_cml_id);
						if ($category_id)
							$data['product_categories'][] = $category_id;
					}
				}

				// Читаем изготовителя, добавляем/обновляем его в базу
				if ($product->Изготовитель)
					$data['manufacturer_id'] = $this->setManufacturer($product->Изготовитель->Наименование, $product->Изготовитель->Ид);

				// Статус по-умолчанию при отсутствии товара на складе
				// Можно реализовать загрузку из свойств
				if ($default_stock_status)
					$data['stock_status_id'] = $default_stock_status;

				// Свойства
				if ($product->ЗначенияСвойств && isset($classifier['attributes']))
					$this->parseAttributes($data, $product->ЗначенияСвойств, $classifier['attributes']);

				// картинки
				if ($product->Картинка)
					$this->parseImages($product->Картинка, $data);
			}

			if ($feature_cml_id) {
				// Предложение с характеристикой
				// Создает характеристику, связь, и опции
				if ($product->ХарактеристикиТовара) {
					$this->log("Характеристика товара, Ид: " . $product_cml_id, 2);
					$error = $this->parseFeature($product, $feature_cml_id, $data);
						if ($error)	return $result;
				}
			}

			if ($product->Штрихкод) {
				if ($feature_cml_id)
					$data['features'][$feature_cml_id]['ean'] 	=  (string)$product->Штрихкод;
				else
					$data['ean'] 	=  (string)$product->Штрихкод;
			}

			$unit_name = $product->БазоваяЕдиница ? (string)$product->БазоваяЕдиница : "шт";

			if ($feature_cml_id)
				$data['features'][$feature_cml_id]['unit'] = $this->parseProductUnit($unit_name, true);
			else
				$data['unit'] = $this->parseProductUnit($unit_name, true);
			unset($unit_name);

		} // foreach

		$this->log("Последний товар, записываем данные", 2);
		// Добавляем или обновляем товар в базе
		$result = $this->setProduct($data, $error);
		if ($error)	return $result;

		return $result;
	} // parseProducts()


	/**
	 * Разбор каталога
	 */
	private function parseDirectory($xml, $classifier) {

		$this->log("==> Начата загрузка каталога с товарами", 2);
		$error = "";

		$directory					= array();
		$directory['cml_id']		= (string)$xml->Ид;
		$directory['name']			= (string)$xml->Наименование;
		$directory['classifier_id']	= (string)$xml->ИдКлассификатора;
		if (isset($classifier['id'])) {
			if ($directory['classifier_id'] <> $classifier['id']) {
				return "Загружаемый каталог не соответствует классификатору";
			}
		}

		// Если полная выгрузка - требуется очистка для текущего магазина: товаров, остатков и пр.
		if ((string)$xml['СодержитТолькоИзменения'] == 'false')  {
			$this->log("[i] Полная выгрузка",1);
			$this->FULL_IMPORT = true;
		}

		// Загрузка товаров
		$result = $this->parseProducts($xml->Товары, $classifier, $error);
		if ($error) return $error;

		$this->log("<== Завершена загрузка каталога с товарами", 2);
		return "";

	} // parseDirectory()


	/**
	 * ****************************** ФУНКЦИИ ДЛЯ ЗАГРУЗКИ ПРЕДЛОЖЕНИЙ ******************************
	 */

	/**
	 * Добавляет склад в базу данных
	 */
	private function addWarehouse($cml_id, $name) {

		$this->log("==> addWarehouse()", 2);
		$this->query("INSERT INTO `" . DB_PREFIX . "warehouse` SET `name` = '" . $this->db->escape($name) . "', `1c_id` = '" . $this->db->escape($cml_id) . "'");
		$warehouse_id = $this->db->getLastId();

		$this->log("<== addWarehouse(), warehouse_id = " . $warehouse_id, 2);
		return $warehouse_id;

	} // addWarehouse()


	/**
	 * Ищет склад по cml_id
	 */
	private function getWarehouseBycml_id($cml_id) {

		$this->log("==> getWarehouseBycml_id(cml_id=".$cml_id.")", 2);
		$query = $this->query('SELECT * FROM `' . DB_PREFIX . 'warehouse` WHERE `1c_id` = "' . $this->db->escape($cml_id) . '"');

		if ($query->num_rows) {
			$this->log("<== getWarehouseBycml_id(), warehouse_id = " . $query->row['warehouse_id'], 2);
			return $query->row['warehouse_id'];
		}

		$this->log("<== getWarehouseBycml_id(), warehouse_id = 0", 2);
		return 0;

	} // getWarehouseBycml_id()


	/**
	 * Возвращает id склада
	 */
	private function setWarehouse($cml_id, $name) {

		$this->log("==> setWarehouse(cml_id=".$cml_id.",name=".$name.")",2);
		// Поищем склад по 1С Ид
		$warehouse_id = $this->getWarehouseBycml_id($cml_id);

		if (!$warehouse_id) {
			$warehouse_id = $this->addWarehouse($cml_id, $name);
		}

		$this->log("<== setWarehouse(), warehouse_id = " . $warehouse_id,2);
		return $warehouse_id;

	} // setWarehouse()


	/**
	 * Получает общий остаток товара
	 */
	private function getQuantity($product_id, &$error) {

		$query = $this->query("SELECT `quantity` FROM `" . DB_PREFIX . "product` WHERE `product_id` = " . $product_id);

		if ($query->num_rows) {
			$this->log("> Общий остаток товара: " . $query->row['quantity'], 2);
			return $query->row['quantity'];
		}

		$error = "Запрос вернул 0 строк. Не найден общий остаток по product_id: " . $product_id;
		return false;

	} // getQuantity()


	/**
	 * Устанавливает общий остаток товара
	 */
	private function setQuantity($product_id, $quantity) {

    	$error = "";

		$quantity_old = $this->getQuantity($product_id, $error);

		if ($error) {
			return $error;
		}

		if ($quantity <> $quantity_old) {
			$this->query("UPDATE `" . DB_PREFIX . "product` SET `quantity` = " . (float)$quantity . " WHERE `product_id` = " . $product_id);
			$this->log("> Обновлен общий остаток товара: product_id: " . $product_id . ", остаток: " . $quantity, 2);
		}

	} // setQuantity()


	/**
	 * Получает остаток товара по фильтру
	 */
	private function getProductQuantity($product_quantity_filter) {

		$where = "";
		foreach ($product_quantity_filter as $field => $value) {
			$where .= ($where ? " AND" : "") . " `" . $field . "` = " . $value;
		}

		$query = $this->query("SELECT `product_quantity_id`,`quantity` FROM `" . DB_PREFIX . "product_quantity` WHERE " . $where);
		if ($query->num_rows) {
			$data_quantity = array(
				'product_quantity_id'	=> $query->row['product_quantity_id'],
				'quantity'				=> $query->row['quantity']
			);
			$this->log("> У товара есть остаток", 2);
			return $data_quantity;
		} else {
			$this->log("> У товара нет остатка", 2);
			return false;
		}

	} // getProductQuantity()


	/**
	 * Обновляет остаток товара
	 */
	private function updateProductQuantity($product_quantity_id, $quantity) {
		$this->query("UPDATE `" . DB_PREFIX . "product_quantity` SET `quantity` = '" . (float)$quantity . "' WHERE `product_quantity_id` = " . $product_quantity_id);
	} // updateProductQuantity()


	/**
	 * Добавляет остаток товара базовой единицы измерения
	 */
	private function addProductQuantity($product_id, $quantity, $warehouse_id = 0, $product_feature_id = 0) {
		$this->log("==> addProductQuantity(product_id=".$product_id.", quantity=".$quantity.", warehouse_id=".$warehouse_id.", product_feature_id=".$product_feature_id.")", 2);

		$this->query("INSERT INTO `" . DB_PREFIX . "product_quantity` SET `quantity` = '" . (float)$quantity . "', `product_id` = " . $product_id . ", `warehouse_id` = " . $warehouse_id . ", `product_feature_id` = " . $product_feature_id);
		$product_quantity_id = $this->db->getLastId();

		$this->log("<== addProductQuantity(), return: " . $product_quantity_id, 2);
		return $product_quantity_id;
	} // addProductQuantity()


	/**
	 * Добавляет остаток товара по фильтру
	 */
	private function addProductQuantityNew($product_quantity_filter, $quantity) {
		$set = "";
		foreach ($product_quantity_filter as $field => $value) {
			$set .= ", `" . $field . "` = " . $value;
		}

		$this->query("INSERT INTO `" . DB_PREFIX . "product_quantity` SET `quantity` = '" . (float)$quantity . "'" . $set);

		$product_quantity_id = $this->db->getLastId();
		$this->log("> Добавлены остатки в товар, product_quantity_id = " . $product_quantity_id, 2);
		return $product_quantity_id;

	} // addProductQuantityNew()


	/**
	 * Сравнивает остаток
	 */
	private function compareProductQuantity($quantities, $quantity, $unit_id = 0, $warehouse_id = 0, $product_feature_id = 0) {
		$this->log("==> compareProductQuantity()", 2);
		$result = array(
			'product_quantity_id' 	=> 0,
			'update'				=> 0
		);
		foreach ($quantities as $product_quantity_id => $quantity_data) {
			if ($quantity_data['unit_id'] == $unit_id && $quantity_data['warehouse_id'] == $warehouse_id && $quantity_data['product_feature_id'] == $product_feature_id) {
				// Если остаток отличается, изменяем
				$result['product_quantity_id'] = $product_quantity_id;
				if ($quantity_data['quantity'] <> $quantity) {
					$result['update'] = 1;
				}
			}
		}
		$this->log("<== compareProductQuantity(), update: " . $result['update'] . ", product_quantity_id: " . $result['product_quantity_id'], 2);
		return $result;
	} // compareProductQuantity()


	/**
	 * Сравнивает массивы и формирует список измененных полей для запроса
	 */
	private function compareArraysNew($data1, $data2) {
		$this->log("==> compareArraysNew()", 2);

		$upd_fields = array();
		if ($query->num_rows) {
			foreach($data1 as $key => $row) {
				if (!isset($data2[$key])) continue;
				if ($row <> $data2[$key]) {
					$upd_fields[] = $key . " = '" . $this->db->escape($data2[$key]) . "'";
					$this->log("[i] Отличается поле '" . $key . "'", 2);
				} else {
					$this->log("[i] Поле '" . $key . "' не имеет отличий", 2);
				}
			}
		}
		$this->log("<== compareArraysNew()", 2);
		return implode(', ', $upd_fields);
	} // compareArraysNew()


	/**
	 * Ищет совпадение данных в массиве данных, при совпадении значений, возвращает ключ второго массива
	 */
	private function findMatch($data1, $data_array) {

		$bestMatch = 0;
		foreach ($data_array as $key2 => $data2) {
			$matches = 0;
			$fields = 0;
			foreach ($data1 as $key1 => $value) {
				if (isset($data2[$key1])) {
					$fields++;
					if ($data2[$key1] == $value) {
						$matches++;
					}

				}
			}
			// у всех найденых полей совпали значения
			if ($matches == $fields){
				return $key2;
			}
		}
		return false;
	} // findMatch()


	/**
	 * Устанавливает остаток товара
	 */
	private function setProductQuantity($product_quantity_filter, $quantity) {

		$product_quantity = $this->getProductQuantity($product_quantity_filter);
		if ($product_quantity == false) {
			$product_quantity_id = $this->addProductQuantityNew($product_quantity_filter, $quantity);
			$this->log("> Добавлен остаток, product_quantity_id = " . $product_quantity_id, 2);
			return $product_quantity_id;
		} else {
			if ($product_quantity['quantity'] != $quantity) {
				$this->updateProductQuantity($product_quantity['product_quantity_id'], $quantity);
				$this->log("> Обновлен остаток, product_quantity_id = " . $product_quantity['product_quantity_id'], 2);
			}
			return $product_quantity['product_quantity_id'];
		}
	} // setProductQuantity()


	/**
	 * Удаляет склад и все остатки поо нему
	 */
	private function deleteWarehouse($id_cml) {

		$warehouse_id = $this->getWarehouseBycml_id($id_cml);
		if ($warehouse_id) {
			// Удаляем все остатки по этму складу
			$this->deleteStockWarehouse($warehouse_id);

			// Удалим остатки по этому складу
			$this->query("DELETE FROM `" . DB_PREFIX . "product_quantity ` WHERE `warehouse_id` = " . (int)$warehouse_id);

			// Удаляем склад
			$this->query("DELETE FROM `" . DB_PREFIX . "warehouse ` WHERE `1c_id` = '" . $this->db->escape($id_cml) . "'");

			$this->log("> Удален склад Ид '" . $id_cml . "' и все остатки на нем.",2);
		}
	}


	/**
	 * Загружает список складов
	 */
	private function parseWarehouses($xml) {

		$data = array();
		foreach ($xml->Склад as $warehouse){
			if (isset($warehouse->Ид) && isset($warehouse->Наименование) ){
				$id_cml = (string)$warehouse->Ид;
				$name = trim((string)$warehouse->Наименование);
				$this->log("> Чтение склада '" . $name . "' из торговой системы",2);
				$delete = isset($warehouse->ПометкаУдаления) ? $warehouse->ПометкаУдаления : "false";
				if ($delete == "false") {

					$data[$id_cml] = array(
						'name' => $name
					);
					$data[$id_cml]['warehouse_id'] = $this->setWarehouse($id_cml, $name);
				} else {
					// Удалить склад
					$this->log("[i] Склад помечен на удаление в торговой системе");
					$this->deleteWarehouse($id_cml);
				}
			}
		}
		return $data;
	} // parseWarehouses()


	/**
	 * Загружает остатки по складам
	 * Возвращает остатки по складам
	 * где индекс - это warehouse_id, а значение - это quantity (остаток)
	 */
	private function parseQuantity($xml, $offers_pack, $data, &$error) {

		$this->log("==> Начато чтение остатков",2);

		$data_quantity = array();

		if (!$xml) {
			return $data_quantity;
		}

		// есть секция с остатками, обрабатываем
		if ($xml->Остаток) {

			foreach ($xml->Остаток as $quantity) {
				// есть секция со складами
				if ($quantity->Склад->Ид) {
					$warehouse_cml_id = (string)$quantity->Склад->Ид;
					$warehouse_id = $this->getWarehouseBycml_id($warehouse_cml_id);
					if (!$warehouse_id) {
						$error = "Склад Ид '" . $warehouse_cml_id . "' не найден с базе";
						return $data_quantity;
					}
				} else {
					$warehouse_id = 0;
				}

				if ($quantity->Склад->Количество) {
					$quantity = (float)$quantity->Склад->Количество;
				}
				$data_quantity[$warehouse_id] = $quantity;
			}

		} elseif ($xml->Склад) {

			foreach ($xml->Склад as $quantity) {

				// есть секция со складами
				$warehouse_cml_id = (string)$quantity['ИдСклада'];
				if ($warehouse_cml_id) {
					$warehouse_id = $this->getWarehouseBycml_id($warehouse_cml_id);
					if (!$warehouse_id) {
						$error = "Склад Ид '" . $warehouse_cml_id . "' не найден с базе";
						return $data_quantity;
					}
				} else {
					$error = "Не указан Ид склада!";
					return $data_quantity;
				}

				$data_quantity[$warehouse_id] = (float)$quantity['КоличествоНаСкладе'];
			}

			// Общий остаток
			if ($xml->Количество) {
				$data_quantity[0] = (float)$xml->Количество;
			}
		}

		$this->log($data_quantity, 2);
		$this->log("<== Завершено чтение остатков",2);
		return $data_quantity;

	} // parseQuantity()


	/**
	 * Возвращает массив данных валюты по id
	 */
	private function getCurrency($currency_id) {
		$this->log("==> getCurrency()",2);
		$query = $this->query("SELECT * FROM `" . DB_PREFIX . "currency` WHERE `currency_id` = " . $currency_id);
		if ($query->num_rows) {
			return $query->row;
		}
		return array();
	} // getCurrency()


	/**
	 * Возвращает id валюты по коду
	 */
	private function getCurrencyId($code) {
		$this->log("==> getCurrencyId()", 2);
		$query = $this->query("SELECT `currency_id` FROM `" . DB_PREFIX . "currency` WHERE `code` = '" . $this->db->escape($code) . "'");
		if ($query->num_rows) {
			$this->log("<== getCurrencyId() currency_id = " . $query->row['currency_id'], 2);
			return $query->row['currency_id'];
		}

		// Попробуем поискать по символу справа
		$query = $this->query("SELECT `currency_id` FROM `" . DB_PREFIX . "currency` WHERE `symbol_right` = '" . $this->db->escape($code) . "'");
		if ($query->num_rows) {
			$this->log("<== getCurrencyId() currency_id = " . $query->row['currency_id'], 2);
			return $query->row['currency_id'];
		}

		$this->log("<== getCurrencyId() currency_id = 0", 2);
		return 0;
	} // getCurrencyId()


	/**
	 * Сохраняет настройки сразу в базу данных
	 */
	private function configSet($key, $value, $store_id=0) {
		if (!$this->config->has('exchange1c_'.$key)) {
			$this->query("INSERT INTO `" . DB_PREFIX . "setting` SET `value` = '" . $value . "', `store_id` = " . $store_id . ", `code` = 'exchange1c', `key` = '" . $key . "'");
		}
	} // configSet()


	/**
	 * Получает список групп покупателей
	 */
	private function getCustomerGroups() {
		$query = $this->query("SELECT `customer_group_id` FROM `" . DB_PREFIX. "customer_group` ORDER BY `sort_order`");
		$data = array();
		foreach ($query->rows as $row) {
			$data[] = $row['customer_group_id'];
		}
		return $data;
	} // getCustomerGroups()


	/**
	 * Загружает типы цен автоматически в таблицу которых там нет
	 */
	private function autoLoadPriceType($xml, &$error) {

		$this->log("Автозагрузка цен из XML...", 2);
		$config_price_type = $this->config->get('exchange1c_price_type');

		if (empty($config_price_type)) {
			$config_price_type = array();
		}

		$update = false;

		// список групп покупателей
		$customer_groups = $this->getCustomerGroups();

		$index = 0;
		foreach ($xml->ТипЦены as $price_type)  {
			$name = trim((string)$price_type->Наименование);
			$delete = isset($price_type->ПометкаУдаления) ? $price_type->ПометкаУдаления : "false";
			$id_cml = (string)$price_type->Ид;
			$priority = 0;
			$found = -1;
			foreach ($config_price_type as $key => $cpt) {
				if (!empty($cpt['id_cml']) && $cpt['id_cml'] == $id_cml) {
					$this->log("Найдена цена по Ид: '" . $id_cml . "'", 2);
					$found = $key;
					break;
				}
				if (strtolower(trim($cpt['keyword'])) == strtolower($name)) {
					$this->log("Найдена цена по наименованию: '" . $name . "'", 2);
					$found = $key;
					break;
				}
				$priority = max($priority, $cpt['priority']);
			}

			// Не найден в настройках, добавляем в настройки
			if ($found >= 0) {

				// Если тип цены помечен на удаление, удалим ее из настроек
				if ($delete == "true") {
					$this->log("Тип цены помечен на удаление, не будет загружен и будет удален из настроек", 2);
					unset($config_price_type[$found]);
					$update = true;
				} else {
					// Обновим Ид
					if ($config_price_type[$found]['id_cml'] != $id_cml) {
						$config_price_type[$found]['id_cml'] = $id_cml;
						$update = true;
					}
				}

			} else {
				// Добавим цену в настройку если он ане помечена на удаление
				$this->log($index, 2);
				$customer_group_id = isset($customer_groups[$index]) ? $customer_groups[$index] : $this->config->get('config_customer_group_id');
				if ($delete == "false") {
					$config_price_type[] = array(
						'keyword' 				=> $name,
						'id_cml' 				=> $id_cml,
						'customer_group_id' 	=> $customer_group_id,
						'quantity' 				=> 1,
						'priority' 				=> $priority,
					);
					$update = true;
				}
			} // if
			$index++;
		} // foreach

        if ($update) {
			if ($this->config->get('exchange1c_price_type')) {
				$this->query("UPDATE `". DB_PREFIX . "setting` SET `value` = '" . $this->db->escape(json_encode($config_price_type)) . "', `serialized` = 1 WHERE `key` = 'exchange1c_price_type'");
				$this->log("Тип цены обновлен в настройках", 2);
	        } else {
				$this->query("INSERT `". DB_PREFIX . "setting` SET `value` = '" . $this->db->escape(json_encode($config_price_type)) . "', `serialized` = 1, `code` = 'exchange1c', `key` = 'exchange1c_price_type'");
				$this->log("Тип цены добавлен в настройки", 2);
	        }
        }

		return $config_price_type;

	} // autoLoadPriceType()


	/**
	 * Загружает типы цен и сразу определяет к каким группам сопоставлены они
	 * Если не сопоставлен ни один тип цен, то цены не будут загружаться
	 */
	private function parsePriceType($xml, &$error) {

		$this->log("==> Начало чтения типов цен из классификатора", 2);

		// Автозагрузка цен
		if ($this->config->get('exchange1c_price_types_auto_load')) {
			$config_price_type = $this->autoLoadPriceType($xml, $error);
		} else {
			$config_price_type = $this->config->get('exchange1c_price_type');
		}

		$data = array();

		if (empty($config_price_type)) {
			$error = "В настройках нет типов цен, укажите вручную или включите автозагрузку цен";
			return $data;
		}

		// Перебираем все цены из CML
		foreach ($xml->ТипЦены as $price_type)  {
			$currency		= isset($price_type->Валюта) ? (string)$price_type->Валюта : "RUB";
			$id_cml			= (string)$price_type->Ид;
		 	$name			= trim((string)$price_type->Наименование);
		 	$code			= $price_type->Код ? $price_type->Код : ($price_type->Валюта ? $price_type->Валюта : '');

			// Найденный индекс цены в настройках
			$found = -1;

			// Перебираем все цены из настроек модуля
			foreach ($config_price_type as $index => $config_type) {

				if ($found >= 0)
					break;

				if (!empty($config_type['id_cml']) && $config_type['id_cml'] == $id_cml) {
					$found = $index;
					break;
				} elseif (strtolower($name) == strtolower($config_type['keyword'])) {
					$found = $index;
					break;
				}

			} // foreach ($config_price_type as $config_type)

			if ($found >= 0) {
				if ($code) {
					$currency_id					= $this->getCurrencyId($code);
				} else {
					$currency_id					= $this->getCurrencyId($currency);
				}
				$data[$id_cml] 					= $config_type;
				$data[$id_cml]['currency'] 		= $currency;
				$data[$id_cml]['currency_id'] 	= $currency_id;
				if ($currency_id) {
					$currency_data = $this->getCurrency($currency_id);
					$rate = $currency_data['value'];
					$decimal_place = $currency_data['decimal_place'];
				} else {
					$rate = 1;
					$decimal_place = 2;
				}
				$data[$id_cml]['rate'] 			= $rate;
				$data[$id_cml]['decimal_place'] = $decimal_place;
				$this->log('> Вид цены: ' . $name,2);
			} else {
				$error .= ($error ? "\n" : "") . "Цена '" . $name . "' не найдена в настройках модуля, Ид = '" . $id_cml . "'";
				return false;
			}

		} // foreach ($xml->ТипЦены as $price_type)

		$this->log("==> Завершено чтение типов цен из классификатора", 2);

		return $data;

	} // parsePriceType()


	/**
	 * Обновляет основную цену в товаре
	 */
	private function setPrice($product_id, $data_prices) {

		$this->log("==> Начато установка основной цены в товар", 2);

		foreach ($data_prices as $price) {
			if ($price['default']) {
				$this->query("UPDATE `" . DB_PREFIX . "product` SET `price` = " . (float)$price['price'] . " WHERE `product_id` = " . $product_id);
				$this->log("Цена обновлена: " . $price['price'], 2);
			} else{
				$this->setDiscountPrice($price, $product_id);
			}
		}

		$this->log("<== Завершена установка основной цены в товар", 2);
		return "";
	}


	/**
	 * Устанавливает цену товара для разных групп покупателей
	 */
	private function setDiscountPrice($price_data, $product_id) {

		$query = $this->query("SELECT `product_discount_id`,`price` FROM `" . DB_PREFIX . "product_discount` WHERE `product_id` = " . $product_id . " AND `customer_group_id` = " . $price_data['customer_group_id']);
		if ($query->num_rows) {
			$product_discount_id = $query->row['product_discount_id'];
		}

		if (empty($product_discount_id)) {
			$query = $this->query("INSERT INTO `" . DB_PREFIX . "product_discount` SET `product_id` = " . $product_id . ", `quantity` = " . $price_data['quantity'] . ", `priority` = " . $price_data['priority'] . ", `customer_group_id` = " . $price_data['customer_group_id'] . ", `price` = '" . (float)$price_data['price'] . "'");

			$product_discount_id = $this->db->getLastId();

		} else {
			$fields = $this->compareArrays($query, $price_data);

			// Если есть расхождения, производим обновление
			if ($fields) {
				$this->query("UPDATE `" . DB_PREFIX . "product_discount` SET " . $fields . " WHERE `product_discount_id` = " . $product_discount_id);
			}
		}

		$this->log("> Установлена дополнительня цена, product_discount_id = " . $product_discount_id, 2);

		return $product_discount_id;

	} // setDiscountPrice()


	/**
	 * Устанавливает цену товара базовой единицы товара
	 */
	private function setProductPrice($price_data, $product_id, $product_feature_id = 0) {

		$query = $this->query("SELECT `product_price_id`,`price` FROM `" . DB_PREFIX . "product_price` WHERE `product_id` = " . $product_id . " AND `customer_group_id` = " . $price_data['customer_group_id'] . " AND `product_feature_id` = " . $product_feature_id);
		if ($query->num_rows) {
			$product_price_id = $query->row['product_price_id'];
		} else {
			$product_price_id = 0;
		}

		if (!$product_price_id) {
			$query = $this->query("INSERT INTO `" . DB_PREFIX . "product_price` SET `product_id` = " . $product_id . ", `product_feature_id` = " . $product_feature_id . ", `customer_group_id` = " . $price_data['customer_group_id'] . ", `price` = '" . (float)$price_data['price'] . "'");

			$product_price_id = $this->db->getLastId();

		} else {
			$fields = $this->compareArrays($query, $price_data);

			// Если есть расхождения, производим обновление
			if ($fields) {
				$this->query("UPDATE `" . DB_PREFIX . "product_price` SET " . $fields . " WHERE `product_id` = " . $product_id . " AND `product_feature_id` = " . $product_feature_id . " AND `customer_group_id` = " . $price_data['customer_group_id']);
			}
		}
		$this->log("> Установлена цена товара, product_price_id = " . $product_price_id, 2);

		return $product_price_id;

	} // setProductPrice()


	/**
	 * Получает по коду его id
	 */
	private function getUnitId($number_code) {

		$query = $this->query("SELECT `unit_id` FROM `" . DB_PREFIX . "unit` WHERE `number_code` = '" . $this->db->escape($number_code) . "'");
		if ($query->num_rows) {
			return $query->row['unit_id'];
		}
		$query = $this->query("SELECT `unit_id` FROM `" . DB_PREFIX . "unit` WHERE `rus_name1` = '" . $this->db->escape($number_code) . "'");
		if ($query->num_rows) {
			return $query->row['unit_id'];
		}

		return 0;

	} // getUnitId()


	/**
	 * Загружает все цены только в одной валюте
	 */
	private function parsePrice($xml, $offers_pack, $data, &$error) {

		$result = array();

		if (!$xml) {
			$error = "XML не содержит данных";
			return $result;
		}

		$this->log("==> Начато чтение цен", 2);
		$this->log($xml, 2);

		foreach ($xml->Цена as $price) {
			$price_cml_id	= (string)$price->ИдТипаЦены;
			$data_price = array();

			foreach ($offers_pack['price_types'] as $config_price_type) {
				$this->log($config_price_type, 2);
				if ($config_price_type['id_cml'] == $price_cml_id) {

					// найдена цена
					$data_price = $config_price_type;

					if (!$data_price) {
						$error = "Не найдена цена товара в настройках по Ид: " . $price_cml_id;
						if ($error) return $error;
					}

					if ($price->ЦенаЗаЕдиницу) {
						$data_price['price']		= (float)$price->ЦенаЗаЕдиницу;
					}

					// автоматическая конвертация в основную валюту CMS
					if ($this->config->get('exchange1c_currency_convert') == 1) {
						if (isset($data_price['rate'])) {
							if ($data_price['rate'] <> 1 && $data_price['rate'] > 0) {
								$data_price['price'] = round((float)$price->ЦенаЗаЕдиницу / (float)$data_price['rate'], $data_price['decimal_place']);
							}
						}
					}

					// Если включено пропускать нулевые цены и новая цена будет нулевой, то старая цена не будет изменена
					if ($this->config->get('exchange1c_ignore_price_zero') == 1 && $data_price['price'] == 0) {
						$this->log("Нулевая цена, старая цена не меняется", 2);
						continue;
					}

					// если это не базовая единица
					if ($price->Коэффициент) {
						$data_price['quantity']		= (float)$price->Коэффициент;
					}

					if ($price->Единица) {
			 			$data_price['unit_name']	= (string)$price->Единица;
			 		} else {
			 			$data_price['unit_name']	= "шт.";
			 		}
					$data_price['unit_id']		= $this->getUnitId($data_price['unit_name']);

					if (!empty($data_price['unit_id'])) {
						// Значит в наименовании единицы измерения был прописан не наименование а международный код
						if (array_search($data_price['unit_name'], $data['unit'])) {
							$data_price['unit_id'] = $data['unit']['unit_id'];
						}
					}

			 		if ($price->Представление) {
					 	$data_price['name']			= (string)$price->Представление;
			 		} else {
			 			$data_price['name']			= $data_price['price'] . " за " . $data_price['unit_name'];
			 		}

		 			// истина если цена для группы по-умолчанию
					$data_price['default'] 	= $data_price['customer_group_id'] == $this->config->get('config_customer_group_id') ? true : false;

					$this->log("Цена '" . $data_price['name'] . "'",2);

			 		$result[$price_cml_id] = $data_price;
					break;
				}
			}

 		}
		$this->log($result, 2);
		return $result;

 	} // parsePrices()


	/**
	 * ХАРАКТЕРИСТИКИ
	 */


	/**
	 * Добавляет опциию по названию
	 */
	private function addOption($name, $type='select') {

		$this->log("==> Начато добавление опциии '" . $name. "'", 2);
		$this->query("INSERT INTO `" . DB_PREFIX . "option` SET `type` = '" . $this->db->escape($type) . "'");
		$option_id = $this->db->getLastId();
		$this->query("INSERT INTO `" . DB_PREFIX . "option_description` SET `option_id` = '" . $option_id . "', `language_id` = " . $this->LANG_ID . ", `name` = '" . $this->db->escape($name) . "'");

		$this->log("<== Завершено добавление опциии успешно: '" . $name. "', option_id = " . $option_id, 2);
		return $option_id;

	} // addOption()


	/**
	 * Получает основную цену товара
	 */
	private function OFFgetProductPrice($product_id) {

		$query = $this->query("SELECT price FROM `" . DB_PREFIX . "product` WHERE `product_id` = '" . $product_id . "'");
        if ($query->num_rows) {
        	$this->log("Основная цена товара: " . $query->row['price'], 2);
        	return $query->row['price'];
       	}

  		$this->log("Ошибка при получении основной цены, товар не найден по product_id: " . $product_id, 2);
       	return false;

	} // getProductPrice()


	/**
	 * Получение наименования производителя по manufacturer_id
	 */
	private function getManufacturerName($manufacturer_id, &$error) {

		if (!$manufacturer_id) {
			$error = "Не указан manufacturer_id";
			return "";
		}

		$query = $this->query("SELECT name FROM `" . DB_PREFIX . "manufacturer` WHERE `manufacturer_id` = " . $manufacturer_id);
		$name = isset($query->row['name']) ? $query->row['name'] : "";

		$this->log("Найдено название производителя: '" . $name . "' по id:" . $manufacturer_id, 2);
		return $name;

	} // getManufacturerName()


	/**
	 * Получение product_id по Ид
	 */
	private function getProductIdByCML($product_cml_id) {

		// Определим product_id
		$query = $this->query("SELECT product_id FROM `" . DB_PREFIX . "product_to_1c` WHERE `1c_id` = '" . $this->db->escape($product_cml_id) . "'");
		$product_id = isset($query->row['product_id']) ? $query->row['product_id'] : 0;

		// Проверим существование такого товара
		if ($product_id) {
			$query = $this->query("SELECT `product_id` FROM `" . DB_PREFIX . "product` WHERE `product_id` = " . (int)$product_id);
			if (!$query->num_rows) {

				// Удалим неправильную связь
				$this->query("DELETE FROM `" . DB_PREFIX . "product_to_1c` WHERE `product_id` = " . (int)$product_id);

				$product_id = 0;
			}
		}

		if ($product_id) {
			$this->log("Найден product_id = " . $product_id . " по Ид " . $product_cml_id, 2);
		} else {
			$this->log("Не найден товар по Ид " . $product_cml_id, 2);
		}

		return $product_id;

	} // getProductIdByCML()


	/**
	 * Получение полей товара name,sku,brand,desc,cats,cat_id
	 */
	private function getProduct($product_id, &$data) {

		$this->log("Читаем несколько полей товара...",2);
		if (!$product_id) return "Не указан product_id";

		$data['product_id'] = $product_id;

		$query = $this->query("SELECT `sku`,`ean`,`manufacturer_id`, `image` FROM `" . DB_PREFIX . "product` WHERE `product_id` = " . $product_id);

		if ($query->num_rows) {
			// Получим sku если он не задан
			$data['sku'] = $query->row['sku'];
			$data['ean'] = $query->row['ean'];

			// Получим наименование производителя, если manufacturer_id указан
			if ($query->row['manufacturer_id']) {
				$data['manufacturer_id']	= $query->row['manufacturer_id'];
				$data['manufacturer']		= $this->getManufacturerName($data['manufacturer_id'], $error);
				if ($error) return $error;
			}
		}

		// нужно было для SEO
		//$data['categories'] = $this->getProductCategories($product_id);

		// Описание товара
		// тоже нужно только для SEO
		//$sql = "SELECT `name`,`description` FROM `" . DB_PREFIX . "product_description` WHERE `product_id` = " . $product_id . " AND `language_id` = " . $this->LANG_ID;
		//$this->log($sql, 2);
		//$query_desc = $this->db->query($sql);
		//if ($query_desc->num_rows) {
		//	$data['description'] 	= $query_desc->row['description'];
		//	$data['name'] 			= $query_desc->row['name'];
		//}

		// id категории товара
		$data['categories'] = $this->getProductCategories($product_id);
		return "";

	} // getProduct()


	/**
	 * Разбивает название по шаблону "[order].[name] [option]"
	 */
	private function splitNameStr($str, $opt_yes = true) {
		//$this->log("==> splitName() string = " . $str, 2);

		$str = trim(str_replace(array("\r","\n"),'',$str));
		$length = mb_strlen($str);
		//$this->log('length: '.$length,2);
		$data = array(
			'order' 	=> 0,
			'name' 		=> "",
			'option' 	=> ""
		);

        $pos_name_start = 0;
		$pos_opt_end = 0;
		$pos_opt_start = $length;

		if ($opt_yes) {
			// Поищем опцию
			$level = 0;
			for ($i = $length; $i > 0; $i--) {
				$char = mb_substr($str,$i,1);
				if ($char == ")") {
					$level++;
					if (!$pos_opt_end)
						$pos_opt_end = $i;
				}
				if ($char == "(") {
					$level--;
					if ($level == 0) {
						$pos_opt_start = $i+1;
						$data['option'] = mb_substr($str, $pos_opt_start, $pos_opt_end-$pos_opt_start);
						$pos_opt_start -= 2;
						//$this->log('pos_opt_start = ' . $pos_opt_start . ', pos_opt_end = ' . $pos_opt_end, 2);
						break;
					}
				}
			}
		}

		// Поищем порядок сортировки, order (обязательно после цифры должна идти точка а после нее пробел!)
		$pos_order_end = 0;
		for ($i = 0; $i < $length; $i++) {
			if (is_numeric(mb_substr($str,$i,1))) {
				//$this->log('order: число '.mb_substr($str,$i,1), 2);
				$pos_order_end++;
				if ($i+1 <= $length && mb_substr($str, $i+1, 1) == ".") {
					$data['order'] = (int)mb_substr($str, 0, $pos_order_end);
					$pos_name_start = $i+2;
				}
			} else {
				// Если первая не цифра, дальше не ищем
				break;
			}
		}

		// Наименование
		$data['name'] = trim(mb_substr($str, $pos_name_start, $pos_opt_start-$pos_name_start));
		//$this->log($data, 2);
		//$this->log("<== splitName()", 2);
		return $data;
	} // splitNameStr()


	/**
	 * Разбор характеристики из каталога и предложения
	 * $offer, $feature_cml_id, $data
	 */
	private function parseFeature($xml, $feature_cml_id, &$data) {

		if (!$xml) return "Пустые данные в XML";

		if (!$feature_cml_id) return "Пустое поле Ид характеристики";

		$this->log("==> Начато чтение характеристики...", 2);

		// Разбиваем название по шаблону {order}. {name} ({option})
		$matches = $this->splitNameStr(htmlspecialchars(trim((string)$xml->Наименование)));
		$feature_order = $matches['order'];
		$product_name = $matches['name'];
		$feature_name = $matches['option'];

		if (empty($product_name))
			return "Имя товара не может быть пустым";

		$feature = array(
			'order'					=> $feature_order,
			'name'					=> $feature_name
		);


		// Опции характеристики
		$feature_options = array();
		if ($xml->ХарактеристикиТовара) {

			// Режим "Характеристика"
			if ($this->config->get('exchange1c_product_options_mode') == 'feature') {
				$option_name = "";
				$option_value = "";
				foreach ($xml->ХарактеристикиТовара->ХарактеристикаТовара as $feature_option) {
					// разбиваем название опции
					$option_name = ($option_name ? "," : "") . (string)$feature_option->Наименование;
					$option_value = ($option_value ? "," : "") . (string)$feature_option->Значение;
				}

				$option_id 			= $this->setOption($option_name);
				$option_value_id 	= $this->setOptionValue($option_id, $option_value, 0);
				$feature_options[$option_value_id]	= array(
					'name'				=> $option_name,
					'value_sort_order'	=> 0,
					'value'				=> $option_value,
					'option_id'			=> $option_id,
					'option_value_id'	=> $option_value_id,
					'subtract'			=> $this->config->get('exchange1c_product_options_subtract') == 1 ? 1 : 0
				);



			} elseif ($this->config->get('exchange1c_product_options_mode') == 'certine') {
				// Отдельные товары
				// НЕ РЕАЛИЗОВАННО
				$this->log("Этот метод еще не реализован", 2);

			} elseif ($this->config->get('exchange1c_product_options_mode') == 'related') {
				foreach ($xml->ХарактеристикиТовара->ХарактеристикаТовара as $feature_option) {

					// ЗНАЧЕНИЕ ОПЦИИ
					$matches_value = $this->splitNameStr((string)$feature_option->Значение);
					$value_sort_order 	= $matches_value['order'];
					$value_name			= $matches_value['name'];
					$value_option		= $matches_value['option'];

					$image	= '';

					// ОПЦИЯ
					$matches_option = $this->splitNameStr((string)$feature_option->Наименование);

					// Тип по-умолчанию, если не будет переопределен
					$option_type = "select";
					switch($matches_option['option']) {
						case 'select':
							$option_type 	= 'select';
							break;
						case 'radio':
							$option_type 	= 'radio';
							break;
						case 'checkbox':
							$option_type 	= 'checkbox';
							break;
						case 'image':
							$option_type 	= 'image';
							$image			= $matches_value['option'] ? "options/" . $matches_value['option'] : "";
							break;
						default:
							$option_type 	= "select";
					}

					$option_id			= $this->setOption($matches_option['name'], $option_type, $matches_option['order']);
					$option_value_id    = $this->setOptionValue($option_id, $value_name, $value_sort_order, $image);

					$feature_options[$option_value_id] = array(
						'option_cml_id'		=> $feature_option->Ид ? (string)$feature_option->Ид : '',
						'subtract'			=> $this->config->get('exchange1c_product_options_subtract') == 1 ? 1 : 0,
						'name'				=> $matches_option['name'],
						'value'				=> $value_name,
						'option_id'			=> $option_id,
						'option_value_id'   => $option_value_id,
						'type'				=> $option_type
					);


				}
			}

		}
		$feature['options'] = $feature_options;
		$data['features'][$feature_cml_id] = $feature;

		$this->log("<== Завершено чтение характеристики", 2);
		return "";

	} // parseFeature()


	/**
	 * Читает типы цен из настроек
	 */
	private function getConfigPriceType() {

		$price_types = $this->config->get('exchange1c_price_type');
		if ($price_types) {
			$this->log("> Настройки типов цен успешно прочитаны...",2);
			return $price_types;
		} else {
			$this->log("> Настройки типов цен пустые",2);
			return array();
		}

	} // getConfigPriceType()


	/**
	 * Разбор предложений
	 */
	private function parseOffers($xml, $offers_pack) {

		$error = "";

		$this->log("==> Начато чтение предложений",2);
		if (!$xml->Предложение) return "Невозможно прочитать предложения, т.к. данные пустые";

		// Массив для хранения данных об одном товаре, все характеристики загружаются в него
		$data = array();

		foreach ($xml->Предложение as $offer) {

			$this->log("=-=-=-= НАЧАЛО ПРЕДЛОЖЕНИЯ =-=-=-=", 2);

			$error = "";

			// Получаем Ид товара и характеристики
			$cml_id 			= explode("#", (string)$offer->Ид);

			$product_cml_id		= $cml_id[0];
			$feature_cml_id 	= isset($cml_id[1]) ? $cml_id[1] : '';

			// Проверка на пустое предложение
			if (empty($product_cml_id)) {
				$this->log("[!] Ид товара пустое, предложение игнорируется!", 2);
				continue;
			}

			$this->log("[i] Ид товара: " . $product_cml_id . ", Ид характеристики: " . $feature_cml_id);

			// Читаем product_id, если нет товара выходим с ошибкой, значит что-то не так
			$product_id = $this->getProductIdByCML($product_cml_id);
			if (!$product_id) {
				continue;
				$error = "Не найден товар в базе по Ид: " . $cml_id;
				if ($error) return $error;
			}

			// ОПРЕДЕЛЯЕМ К КАКОМУ ТОВАРУ ОТНОСИТСЯ ПРЕДЛОЖЕНИЕ
			if (isset($data['product_id'])) {
				//$this->log("[i] Есть предыдущее предложение",2);
				if ($data['product_id'] == $product_id) {
					$this->log("[i] Предложение относится к предыдущему товару, добавляем предложения", 2);

				} else {
					$this->log("[i] Предложение нового товара, нужно обработать предыдущие предложения и очистить данные", 2);
					$error = $this->updateProduct($data);
					if ($error) return $error;

					$data = array();
					// Записывает в data: product_id,name,sku,brand,desc,cats,cat_id
					// Только когда первый раз читается новый товар
					$error = $this->getProduct($product_id, $data);
					if ($error) return $error;

				}
			} else {
				$this->log("[i] Пустые данные, первый товар", 2);
				// Записывает в data: product_id,name,sku,brand,desc,cats,cat_id
				// Только когда первый раз читается новый товар
				$error = $this->getProduct($product_id, $data);
				if ($error) return $error;
			}

			//$this->log("Товар: '" . $data['name'] . "'");

			// Базовая единица измерения
			if ($offer->БазоваяЕдиница)
				$data['unit'] = $this->parseProductUnit($offer->БазоваяЕдиница);

			if ($feature_cml_id) {
				// Предложение с характеристикой
				// Создает характеристику, связь, и опции
				if ($offer->ХарактеристикиТовара) {
					$error = $this->parseFeature($offer, $feature_cml_id, $data);
					if ($error) return $error;
				} else {
					// Если нет секции характеристика (XML 2.07, УТ 11.3)
					$this->log("Нет секции <ХарактеристикаТовара>");
					$this->log($data,2);
					$this->log($offer,2);
					$name = (string)$offer->Наименование;
					$split_name = $this->splitNameStr($name, true);
                    $option_name 		= "Характеристика";
                    $option_value		= $split_name['option'];

					$data['features'][$feature_cml_id]['name'] = $option_value;

					$option_id 			= $this->setOption($option_name);
					$option_value_id 	= $this->setOptionValue($option_id, $option_value, 0);
					$data['features'][$feature_cml_id]['options'][$option_value_id]	= array(
						'name'				=> $option_name,
						'value_sort_order'	=> 0,
						'value'				=> $option_value,
						'option_id'			=> $option_id,
						'option_value_id'	=> $option_value_id,
						'subtract'			=> $this->config->get('exchange1c_product_options_subtract') == 1 ? 1 : 0
					);

				}
			}

			if ($offer->Штрихкод) {
				if ($feature_cml_id) {
					$data['features'][$feature_cml_id]['ean'] = (string)$offer->Штрихкод;
				} else {
					$data['ean'] = (string)$offer->Штрихкод;
				}
			}

			// оставлено для совместимости - не используется
			if ($offer->Количество) {
				if (!isset($data['quantity'])) {
					$data['quantity'] = 0;
				}

				if ($feature_cml_id) {
					// штрихкод характеристики
					$data['features'][$feature_cml_id]['quantity'] = (float)$offer->Количество;
					$data['quantity'] += (float)$offer->Количество;
				} else {
					// Общее количество
					$data['quantity'] = (float)$offer->Количество;
				}
			}

			if ($offer->Цены) {
				if ($feature_cml_id) {
					if (!isset($offers_pack['price_types']))
						$offers_pack['price_types'] = $this->getConfigPriceType();
					$data['features'][$feature_cml_id]['prices'] = $this->parsePrice($offer->Цены, $offers_pack, $data, $error);
				} else {
					$data['prices'] = $this->parsePrice($offer->Цены, $offers_pack, $data, $error);
				}
			}
			if ($error) return $error;

			// остатки CML <= 2.08
			if ($offer->Склад) {
				if ($feature_cml_id) {
					$data['features'][$feature_cml_id]['quantities'] = $this->parseQuantity($offer, $offers_pack, $data, $error);
				} else {
					$data['quantities'] = $this->parseQuantity($offer, $offers_pack, $data, $error);
				}
			}
			if ($error) return $error;

			// остатки CML = 2.09
			if ($offer->Остатки) {
				if ($feature_cml_id) {
					$quantities = $this->parseQuantity($offer->Остатки, $offers_pack, $data, $error);
					$data['features'][$feature_cml_id]['quantities'] = $quantities;
				} else {
					$data['quantities'] = $this->parseQuantity($offer->Остатки, $offers_pack, $data, $error);
				}
			}
			if ($error) return $error;

			// общий остаток
			if (isset($data['features'][$feature_cml_id]['quantities'])) {
				$quantity_total = 0;
				foreach ($data['features'][$feature_cml_id]['quantities'] as $quantity) {
					$quantity_total += $quantity[0];
				}

				if (isset($data['quantity'])) {
					$data['quantity'] += $quantity_total;
				} else {
					$data['quantity'] = $quantity_total;
				}
			}

			$data['product_cml_id'] = $product_cml_id;
			$data['product_id'] 	= $product_id;

		} // foreach()

		// Обновляем последний товар
		if (isset($data['product_id'])) {
			$error = $this->updateProduct($data);
			if ($error) return $error;
		}

		$this->log("<== Завершено чтение предложений", 2);
		return $error;
	} // parseOffers()


	/**
	 * Загружает пакет предложений
	 */
	private function parseOffersPack($xml) {

		$error = "";

		$offers_pack = array();
		$offers_pack['offers_pack_id']	= (string)$xml->Ид;
		$offers_pack['name']			= (string)$xml->Наименование;
		$offers_pack['directory_id']	= (string)$xml->ИдКаталога;
		$offers_pack['classifier_id']	= (string)$xml->ИдКлассификатора;

		// Сопоставленные типы цен
		if ($xml->ТипыЦен) {
			$this->log("> Чтение типов цен...",2);
			$offers_pack['price_types'] = $this->parsePriceType($xml->ТипыЦен, $error);
		}
		if ($error) return $error;

		// Загрузка складов
		if ($xml->Склады) {
			$offers_pack['warehouses'] = $this->parseWarehouses($xml->Склады);
		}

		// Загружаем предложения
		$error = $this->parseOffers($xml->Предложения, $offers_pack);

		return $error;
	 } // parseOffersPack()


	/**
	 * ****************************** ФУНКЦИИ ДЛЯ ЗАГРУЗКИ ЗАКАЗОВ ******************************
	 */

	/**
	 * Меняет статусы заказов
	 *
	 * @param	int		exchange_status
	 * @return	bool
	 */
	private function sendMail($subject, $order_info) {

		$this->log("==> sendMail()",2);
		$message = 'Изменился статус Вашего заказа!';

		$mail = new Mail();
		$mail->protocol = $this->config->get('config_mail_protocol');
		$mail->parameter = $this->config->get('config_mail_parameter');
		$mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
		$mail->smtp_username = $this->config->get('config_mail_smtp_username');
		$mail->smtp_password = html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
		$mail->smtp_port = $this->config->get('config_mail_smtp_port');
		$mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');

		$mail->setTo($order_info['email']);
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender(html_entity_decode($order_info['store_name'], ENT_QUOTES, 'UTF-8'));
		$mail->setSubject(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
		$mail->setText($message);
		$mail->send();
		//$this->log($mail,2);

	} // sendMail()

	/**
	 * Меняет статусы заказов
	 *
	 * @param	int		exchange_status
	 * @return	bool
	 */
	public function queryOrdersStatus($params) {
		if ($params['exchange_status'] != 0) {
			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_status_id` = " . $params['exchange_status']);
			$this->log("> Поиск заказов со статусом id: " . $params['exchange_status'],2);
		} else {
			$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `date_added` >= '" . $params['from_date'] . "'");
			$this->log("> Поиск заказов с даты: " . $params['from_date'],2);
		}
		if ($query->num_rows) {
			foreach ($query->rows as $order_data) {

				//$this->log('order_data:',2);
				//$this->log($order_data,2);

				if ($order_data['order_status_id'] == $params['new_status']) {
					$this->log("> Cтатус заказа #" . $order_data['order_id'] . " не менялся.",2);
					continue;
				}

				// Меняем статус
				$sql = "UPDATE `" . DB_PREFIX . "order` SET `order_status_id` = '" . $params['new_status'] . "' WHERE `order_id` = '" . $order_data['order_id'] . "'";
				$this->log($sql,2);
				$query = $this->db->query($sql);
				$this->log("> Изменен статус заказа #" . $order_data['order_id'],1);
				// Добавляем историю в заказ
				$sql = "INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = '" . $order_data['order_id'] . "', `comment` = 'Ваш заказ обрабатывается', `order_status_id` = " . $params['new_status'] . ", `notify` = 0, `date_added` = '" . $this->NOW . "'";
				$this->log($sql,2);
				$query = $this->db->query($sql);
				$this->log("> Добавлена история в заказ #" . $order_data['order_id'],2);

				// Уведомление
				if ($params['notify']) {
					$this->log("> Отправка уведомления на почту: " . $order_data['email'],2);
					$this->sendMail('Статус Вашего заказа изменен', $order_data);
				}
			}
		}
		return 1;
	}


	/**
	 * Получает название статуса документа на текущем языке
	 *
	 */
	private function getOrderStatusName($order_staus_id) {
		if (!$this->LANG_ID) {
			$this->LANG_ID = $this->getLanguageId($this->config->get('config_language'));
		}
		$query = $this->query("SELECT `name` FROM `" . DB_PREFIX . "order_status` WHERE `order_status_id` = " . $order_staus_id . " AND `language_id` = " . $this->LANG_ID);
		if ($query->num_rows) {
			return $query->row['name'];
		}
		return "";
	} // getOrderStatusName()


	/**
	 * Получает название цены из настроек по группе покупателя
	 *
	 */
	private function getPriceTypeName($customer_group_id) {
		if (!$customer_group_id)
			return "";

		$config_price_type = $this->config->get('exchange1c_price_type');
		if (!$config_price_type)
			return "";

		foreach ($config_price_type as $price_type) {
			if ($price_type['customer_group_id'] == $customer_group_id)
				return $price_type['keyword'];
		}

		return "";
	} // getPriceTypeName()


	/**
	 * Получает CML Ид характеристики по выбранным опциям
	 *
	 */
	private function getFeatureCML($order_id, $product_id) {

		$order_options = $this->model_sale_order->getOrderOptions($order_id, $product_id);
		//$this->log($order_options,2);
		$options = array();
		foreach ($order_options as $order_option) {
			$options[$order_option['product_option_id']] = $order_option['product_option_value_id'];
		}

		$product_feature_id = 0;
		foreach ($order_options as $order_option) {
			$sql = "SELECT `product_feature_id` FROM `" . DB_PREFIX . "product_feature_value` WHERE `product_option_value_id` = " . (int)$order_option['product_option_value_id'];
			$this->log($sql,2);
			$query = $this->db->query($sql);

			if ($query->num_rows) {
				if ($product_feature_id) {
					if ($product_feature_id != $query->row['product_feature_id']) {
						$this->log("[ОШИБКА] По опциям товара найдено несколько характеристик!");
						return false;
					}
				} else {
					$product_feature_id = $query->row['product_feature_id'];
				}

			}

		}
		//$this->log($product_feature_id,2);

		$feature_cml_id = "";
		if ($product_feature_id) {
			// Получаем Ид
			$sql = "SELECT 1c_id FROM `" . DB_PREFIX . "product_feature` WHERE `product_feature_id` = " . (int)$product_feature_id;
			$this->log($sql,2);
			$query = $this->db->query($sql);
			if ($query->num_rows) {
				$feature_cml_id = $query->row['1c_id'];
			}
			$features[$product_feature_id] = $feature_cml_id;
		}

		//$this->log($feature_cml_id,2);
		return $feature_cml_id;

	} // getFeatureCML


	/**
	 * ****************************** ФУНКЦИИ ДЛЯ ВЫГРУЗКИ ЗАКАЗОВ ******************************
	 */
	public function queryOrders($params) {
		$this->log("==== Выгрузка заказов ====",2);

		$version = $this->config->get('exchange1c_CMS_version');
		if (version_compare($version, '2.0.3.1', '>')) {
			$this->log("customer/customer_group",2);
			$this->load->model('customer/customer_group');
		} else {
			$this->log("sale/customer_group",2);
			$this->load->model('sale/customer_group');
		}

		$this->load->model('sale/order');

		if ($params['exchange_status'] != 0) {
			// Если указано с каким статусом выгружать заказы
			$sql = "SELECT order_id FROM `" . DB_PREFIX . "order` WHERE `order_status_id` = " . $params['exchange_status'];
			$this->log($sql,2);
			$query = $this->db->query($sql);
		} else {
			// Иначе выгружаем заказы с последей выгрузки, если не определа то все
			$query = $this->db->query("SELECT order_id FROM `" . DB_PREFIX . "order` WHERE `date_added` >= '" . $params['from_date'] . "'");
		}

		$document = array();
		$document_counter = 0;

		if ($query->num_rows) {
			foreach ($query->rows as $orders_data) {
				$order = $this->model_sale_order->getOrder($orders_data['order_id']);
				$this->log("> Выгружается заказ #" . $order['order_id']);
				$date = date('Y-m-d', strtotime($order['date_added']));
				$time = date('H:i:s', strtotime($order['date_added']));
				if (version_compare($version, '2.0.3.1', '>')) {
					$customer_group = $this->model_customer_customer_group->getCustomerGroup($order['customer_group_id']);
				} else {
					$customer_group = $this->model_sale_customer_group->getCustomerGroup($order['customer_group_id']);
				}
				$document['Документ' . $document_counter] = array(
					 'Ид'          => $order['order_id']
					,'Номер'       => $order['order_id']
					,'Дата'        => $date
					,'Время'       => $time
					,'Валюта'      => $params['currency']
					,'Курс'        => 1
					,'ХозОперация' => 'Заказ товара'
					,'Роль'        => 'Продавец'
					,'Сумма'       => $order['total']
					,'Комментарий' => $order['comment']
					,'Соглашение'  => $customer_group['name']
				);

				// Разбирает ФИО
				$user = explode(",", $order['payment_firstname']);
				array_unshift($user, $order['payment_lastname']);
				array_unshift($user, $order['payment_patronymic']);
				$username = implode(" ", $user);

				// Контрагент
				$document['Документ' . $document_counter]['Контрагенты']['Контрагент'] = array(
					 'Ид'                 => $order['customer_id'] . '#' . $order['email']
					//,'РасчетныеСчета'		=> array(
					//	'НомерСчета'			=> '12345678901234567890'
					//	,'Банк'					=> ''
					//	,'БанкКорреспондент'	=> ''
					//	,'Комментарий'			=> ''
					//)
					//---
					,'Роль'               => 'Покупатель'
					,'ПолноеНаименование' => $username
					,'Фамилия'            => $order['payment_lastname']
					,'Имя'			      => $order['payment_firstname']
					,'Отчество'		      => $order['payment_patronymic']
					,'АдресРегистрации' => array(
						'Представление'	=> $order['shipping_address_1'].', '.$order['shipping_city'].', '.$order['shipping_postcode'].', '.$order['shipping_country']
					)
					,'Контакты' => array(
						'Контакт1' => array(
							'Тип' => 'ТелефонРабочий'
							,'Значение'	=> $order['telephone']
						)
						,'Контакт2'	=> array(
							 'Тип' => 'Почта'
							,'Значение'	=> $order['email']
						)
					)
				);

				// если плательщиком является организация
				$current_customer = &$document['Документ' . $document_counter]['Контрагенты']['Контрагент'];
				if ($order['payment_company']) {
					$current_customer['ИНН'] 						= $order['payment_inn'];
					$current_customer['ОфициальноеНаименование'] 	= $order['payment_company'];
					$current_customer['ПолноеНаименование'] 		= $order['payment_company'];
					//$current_customer['ОКПО'] 					= $order['payment_okpo'];
				} elseif ($order['shipping_company']) {
					$current_customer['ИНН'] 						= $order['shipping_inn'];
					$current_customer['ОфициальноеНаименование'] 	= $order['shipping_company'];
					$current_customer['ПолноеНаименование'] 		= $order['shipping_company'];
					//$current_customer['ОКПО'] 					= $order['shipping_okpo'];
				} else {

					$current_customer['Наименование'] = $username;
				}

    				// Реквизиты документа передаваемые в 1С
				$document['Документ' . $document_counter]['ЗначенияРеквизитов'] = array(
					'ЗначениеРеквизита0' => array(
						'Наименование' => 'Дата отгрузки',
						'Значение' => $date
					)
					,'ЗначениеРеквизита1' => array(
						'Наименование' => 'Статус заказа',
						'Значение' => $this->getOrderStatusName($order['order_status_id'])
					)
					,'ЗначениеРеквизита2' => array(
						'Наименование' => 'Вид цен',
						'Значение' => $this->getPriceTypeName($order['customer_group_id'])
					)
//					,'ЗначениеРеквизита3' => array(
//						'Наименование' => 'Склад',
//						'Значение' => $this->getWarehouseName($order['warehouse_id']);
//					)
//					,'ЗначениеРеквизита4' => array(
//						'Наименование' => 'Организация',
//						'Значение' => $this->getOrganizationName($order['organization_id']);
//					)
//					,'ЗначениеРеквизита5' => array(
//						'Наименование' => 'Подразделение',
//						'Значение' => 'Интернет-магазин'
//					)
//					,'ЗначениеРеквизита6' => array(
//						'Наименование' => 'Сумма включает НДС',
//						'Значение' => true
//					)
//					,'ЗначениеРеквизита7' => array(
//						'Наименование' => 'Договор контрагента',
//						'Значение' => 'Основной договор'
//					)
				);

				// Товары
				$products = $this->model_sale_order->getOrderProducts($orders_data['order_id']);

				$product_counter = 0;
				foreach ($products as $product) {
					$document['Документ' . $document_counter]['Товары']['Товар' . $product_counter] = array(
						 'Ид'             => $this->getcml_idByProductId($product['product_id'])
						,'Наименование'   => $product['name']
						,'ЦенаЗаЕдиницу'  => $product['price']
						,'Количество'     => $product['quantity']
						,'Сумма'          => $product['total']
						,'Резерв' 		  => $product['quantity']
						,'Скидки'         => array('Скидка' => array(
							'УчтеноВСумме' => 'false'
							,'Сумма' => 0
							)
						)
						,'ЗначенияРеквизитов' => array(
							'ЗначениеРеквизита' => array(
								'Наименование' => 'ТипНоменклатуры'
								,'Значение' => 'Товар'
							)
						)
					);
					$current_product = &$document['Документ' . $document_counter]['Товары']['Товар' . $product_counter];
					// Базовая единица будет выгружаться из таблицы product_unit
					$current_product['БазоваяЕдиница'] = array(
						'Код' 					=> '796',
						'НаименованиеПолное' 	=> 'Штука'
					);

					// Характеристики
					//$this->log($order,2);
					$feature_cml_id = $this->getFeatureCML($orders_data['order_id'], $product['order_product_id']);
					if ($feature_cml_id) {
						$document['Документ' . $document_counter]['Товары']['Товар' . $product_counter]['Ид'] .= "#" . $feature_cml_id;
					}

					$product_counter++;
				}

				$document_counter++;

			} // foreach ($query->rows as $orders_data)
		}

		// Формируем заголовок
		$root = '<?xml version="1.0" encoding="utf-8"?><КоммерческаяИнформация ВерсияСхемы="2.07" ДатаФормирования="' . date('Y-m-d', time()) . '" />';
		$root_xml = new SimpleXMLElement($root);
		$xml = $this->array_to_xml($document, $root_xml);

		// Проверка на запись файлов в кэш
		$cache = DIR_CACHE . 'exchange1c/';
		if (is_writable($cache)) {
			// запись заказа в файл
			$f_order = fopen(DIR_CACHE . 'exchange1c/orders.xml', 'w');
			fwrite($f_order, $xml->asXML());
			fclose($f_order);
		} else {
			$this->log("Папка " . $cache . " не доступна для записи, файл заказов не может быть сохранен!",1);
		}

		return $xml->asXML();
	}


	/**
	 * Адрес
	 */
	private function parseAddress($xml) {
		if (!$xml) return "";
		return (string)$xml->Представление;
	} // parseAddress()


	/**
	 * Банк
	 */
	private function parseBank($xml) {
		if (!$xml) return "";
		return array(
			'correspondent_account'	=> (string)$xml->СчетКорреспондентский,
			'name'					=> (string)$xml->Наименование,
			'bic'					=> (string)$xml->БИК,
			'address'				=> $this->parseAddress($xml->Адрес)
		);
	} // parseBank()


	/**
	 * Расчетные счета
	 */
	private function parseAccount($xml) {
		if (!$xml) return "";
		$data = array();
		foreach ($xml->РасчетныйСчет as $object) {
			$data[]	= array(
				'number'	=> $object->Номерсчета,
				'bank'		=> $this->parseBank($object->Банк)
			);
		}
		return $data;
	} // parseAccount()


	/**
	 * Владелец
	 */
	private function parseOwner($xml) {
		if (!$xml) return "";
		return array(
			'id'		=> (string)$xml->Ид,
			'name'		=> (string)$xml->Наименование,
			'fullname'	=> (string)$xml->ПолноеНаименование,
			'inn'		=> (string)$xml->ИНН,
			'account'	=> $this->parseAccount($xml->РасчетныеСчета)
		);
	} // parseOwner()


	/**
	 * Возвращает курс валюты
	 */
	private function getCurrencyValue($code) {
		$query = $this->query("SELECT `value` FROM `" . DB_PREFIX . "currency` WHERE `code` = '" . $code . "'");
		if ($query->num_rows) {
			return $query->row['value'];
		}
		return 1;
	} // getCurrencyValue()


	/**
	 * Возвращает валюту по коду
	 * Это временное решение
	 */
	private function getCurrencyByCode($code) {
		$data = array();
		if ($code == "643") {
			$data['currency_id'] = $this->getCurrencyId("RUB");
			$data['currency_code'] = "RUB";
			$data['currency_value'] = $this->getCurrencyValue("RUB");
		}
		return $data;
	} // getCurrencyByCode()


	/**
	 * Устанавливает опции заказа в товаре
	 */
	private function setOrderProductOptions($order_id, $product_id, $order_product_id, $product_feature_id = 0) {
		$this->log("==> setOrderProductOptions()",2);
		// удалим на всякий случай если были
		$this->query("DELETE FROM `" . DB_PREFIX . "order_option` WHERE `order_product_id` = " . $order_product_id);

		// если есть, добавим
		if ($product_feature_id) {
			$query_feature = $this->query("SELECT `pfv`.`product_option_value_id`,`pf`.`name` FROM `" . DB_PREFIX . "product_feature_value` `pfv` LEFT JOIN `" . DB_PREFIX . "product_feature` `pf` ON (`pfv`.`product_feature_id` = `pf`.`product_feature_id`) WHERE `pfv`.`product_feature_id` = " . $product_feature_id . " AND `pfv`.`product_id` = " . $product_id);
			$this->log($query_feature,2);
			foreach ($query_feature->rows as $row_feature) {
				$query_options = $this->query("SELECT `pov`.`product_option_id`,`pov`.`product_option_value_id`,`po`.`value`,`o`.`type` FROM `" . DB_PREFIX . "product_option_value` `pov` LEFT JOIN `" . DB_PREFIX . "product_option` `po` ON (`pov`.`product_option_id` = `po`.`product_option_id`) LEFT JOIN `" . DB_PREFIX . "option` `o` ON (`o`.`option_id` = `pov`.`option_id`) WHERE `pov`.`product_option_value_id` = " . $row_feature['product_option_value_id']);
				$this->log($query_options,2);
				foreach ($query_options->rows as $row_option) {
					$this->query("INSERT INTO `" . DB_PREFIX . "order_option` SET `order_id` = " . $order_id . ", `order_product_id` = " . $order_product_id . ", `product_option_id` = " . $row_option['product_option_id'] . ", `product_option_value_id` = " . $row_option['product_option_value_id'] . ", `name` = '" . $this->db->escape($row_option['value']) . "', `value` = '" . $this->db->escape($row_feature['name']) . "', `type` = '" . $row_option['type'] . "'");
					$order_option_id = $this->db->getLastId();
					$this->log("order_option_id: ".$order_option_id,2);
				}
			}
		}
		$this->log("<== setOrderProductOptions()",2);
	} // setOrderProductOptions()


	/**
	 * Добавляет товар в заказ
	 */
	private function addOrderProduct($order_id, $product_id, $price, $quantity, $total, $tax = 0, $reward = 0) {
		$this->log("==> addOrderProduct()",2);

		$query = $this->query("SELECT `pd`.`name`,`p`.`model` FROM `" . DB_PREFIX . "product` `p` LEFT JOIN `" . DB_PREFIX . "product_description` `pd` ON (`p`.`product_id` = `pd`.`product_id`) WHERE `p`.`product_id` = " . $product_id);
		if ($query->num_rows) {
			$name = $query->row['name'];
			$model = $query->row['model'];

			$sql = "";
			$sql .= ($tax) ? ", `tax` = " . $tax : "";
			$sql .= ($reward) ? ", `reward` = " . $reward : "";
			$this->query("INSERT INTO `" . DB_PREFIX . "order_product` SET `product_id` = " . $product_id . ",
				`order_id` = " . $order_id . ",
				`name` = '" . $this->db->escape($name) . "',
				`model` = '" . $this->db->escape($model) . "',
				`price` = " . $price . ",
				`quantity` = " . $quantity . ",
				`total` = " . $total . $sql);
			return $this->db->getLastId();
		}
		return 0;
		$this->log("<== addOrderProduct()",2);
	} // addOrderProduct()


	/**
	 * Удаляем товар из заказа со всеми опциями
	 */
	private function deleteOrderProduct($order_product_id) {
		$this->log("==> deleteOrderProduct()",2);

		$this->query("DELETE FROM `" . DB_PREFIX . "order_product` WHERE `order_product_id` = " . $order_product_id);
		$this->query("DELETE FROM `" . DB_PREFIX . "order_option` WHERE `order_product_id` = " . $order_product_id);
		$this->log("<== deleteOrderProduct()",2);
	} // deleteOrderProduct()


	/**
	 * Меняет статус заказа
	 */
	private function getOrderStatusLast($order_id) {
		$this->log("==> getOrderStatusLast()",2);
		$query = $this->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = " . $order_id . " ORDER BY `date_added` DESC LIMIT 1");
		if ($query->num_rows) {
			$this->log("<== getOrderStatusLast() return: " . $query->row['order_status_id'],2);
			return $query->row['order_status_id'];
		}
		return 0;
		$this->log("<== getOrderStatusLast() return: 0",2);
	}


	/**
	 * Меняет статус заказа
	 */
	private function changeOrderStatus($order_id, $status_name) {
		$this->log("==> changeOrderStatus()",2);

		$query = $this->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `language_id` = " . $this->LANG_ID . " AND `name` = '" . $this->db->escape($status_name) . "'");
		if ($query->num_rows) {
			$new_order_status_id = $query->row['order_status_id'];
		} else {
			$this->log("[ОШИБКА] Статус с названием '" . $status_name . "' не найден");
			return 0;
 		}
		$this->log("[i] Статус id у названия '" . $status_name . "' определен как " . $new_order_status_id,2);

		// получим старый статус
		$order_status_id = $this->getOrderStatusLast($order_id);
		if (!$order_status_id) {
			$this->log("[ОШИБКА] Ошибка получения старого статуса документа!");
			return 0;
		}

		if ($order_status_id == $new_order_status_id) {
			$this->log("[!] Статус документа не изменился");
			return 1;
		}

		// если он изменился, изменим в заказе
		$this->query("INSERT INTO `" . DB_PREFIX . "order_history` SET `order_id` = " . $order_id . ", `order_status_id` = " . $new_order_status_id . ", `date_added` = '" . $this->NOW . "'");

		$this->log("<== changeOrderStatus()",2);
		return 2;
	} // changeOrderStatus()


	/**
	 * Обновляет документ
	 */
	private function updateDocument($doc, $order, $products) {
		$this->log("==> updateDocument()",2);

		$this->log($doc,2);
		$this->log($order,2);
		$this->log($products,2);

		$order_fields = array();

		// обновим входящий номер
		if (!empty($doc['invoice_no'])) {
			$order_fields['invoice_no'] = $doc['invoice_no'];
		}

		// проверим валюту
		if (!empty($doc['currency'])) {
			$currency = $this->getCurrencyByCode($doc['currency']);
			$order_fields['currency_id'] = $currency['currency_id'];
			$order_fields['currency_code'] = $currency['currency_code'];
			$order_fields['currency_value'] = $currency['currency_value'];
		}

		// проверим сумму
		if (!empty($doc['total'])) {
			if ($doc['total'] != $order['total']) {
				$order_fields['total'] = $doc['total'];
			}
		}

		// статус заказа
		if (!empty($doc['status'])) {
			$result = $this->changeOrderStatus($doc['order_id'], $doc['status']);
		}
		if (!$result)
			return "Ошибка при смене статуса заказа!";

		$old_products = $products;

		// проверим товары, порядок должен быть такой же как и в 1С
		if (!empty($doc['products'])) {
			foreach ($doc['products'] as $key => $doc_product) {

            	$this->log("Товар: ".$doc_product['name'],2);

				$order_product_fields = array();
				$order_option_fields = array();
				$update = false;
				$product_feature_id = isset($doc_product['product_feature_id']) ? $doc_product['product_feature_id'] : 0;

				if (isset($products[$key])) {
					$product = $products[$key];
					$order_product_id = $product['order_product_id'];

					unset($old_products[$key]);

					// получим характеристику товара в заказе
					$old_feature_cml_id = $this->getFeatureCML($doc['order_id'], $order_product_id);
					$this->log("old_feature_cml_id: ".$old_feature_cml_id,2);
					$this->log("new_feature_cml_id: ".$doc_product['product_feature_cml_id'],2);

					// сравним
					if ($doc_product['product_id'] == $product['product_id']) {
						$update = true;
						if ($old_feature_cml_id != $doc_product['product_feature_cml_id']) {
							// изменить характеристику
							$this->setOrderProductOptions($doc['order_id'], $doc_product['product_id'], $order_product_id, $product_feature_id);
						}
						// обновим если менялось количество или цена
						if ($product['quantity'] != $doc_product['quantity'] || $product['price'] != $doc_product['price']) {
							$order_product_fields[] = "`quantity` = " . $doc_product['quantity'];
							$order_product_fields[] = "`price` = " . $doc_product['price'];
							$order_product_fields[] = "`total` = " . $doc_product['total'];
							//$order_product_fields[] = "`tax` = " . $doc_product['tax'];
							//$order_product_fields[] = "`reward` = " . $doc_product['reward'];
						}
					} else {
						// товар отличается, заменить полностью
						$order_product_fields[] = "`product_id` = " . $doc_product['product_id'];
						$order_product_fields[] = "`name` = '" . $this->db->escape($doc_product['product_id']) . "'";
						$order_product_fields[] = "`model` = '" . $this->db->escape($doc_product['model']) . "'";
						$order_product_fields[] = "`price` = " . $doc_product['price'];
						$order_product_fields[] = "`quantity` = " . $doc_product['quantity'];
						$order_product_fields[] = "`total` = " . $doc_product['total'];
						$order_product_fields[] = "`tax` = " . $doc_product['tax'];
						// бонусные баллы
						$order_product_fields[] = "`reward` = " . $doc_product['reward'];

						// заменить опции, если есть
						// считать опции с характеристики и записать в заказ у товара $order_product_id
						$this->setOrderProductOptions($doc['order_id'], $doc_product['product_id'], $order_product_id, $product_feature_id);

					} // if
				} else {
					// Добавить товар в документ
					$order_product_id = $this->addOrderProduct($doc['order_id'], $doc_product['product_id'], $doc_product['price'], $doc_product['quantity'], $doc_product['total']);
					if ($order_product_id && $product_feature_id) {
						// добавлен товар и есть опции
						$this->setOrderProductOptions($doc['order_id'], $doc_product['product_id'], $order_product_id, $product_feature_id);
					}

				}// if (isset($products[$key]))
				$this->log("update: ".$update,2);
				$this->log("fields: ",2);
				$this->log($order_product_fields,2);
				// если надо обновить поля товара в заказе
				if ($order_product_fields) {
					$fields = implode(", ", $order_product_fields);
					if ($update) {
						$this->query("UPDATE `" . DB_PREFIX . "order_product` SET " . $fields . " WHERE `order_product_id` = " . $products[$key]['order_product_id']);
						$this->log("Товар '" . $doc_product['name'] . "' обновлен в заказе",2);
					} else {

					}
				} else {
					$this->log("Товар '" . $doc_product['name'] . "' в заказе не изменился",2);
				}
			} // foreach

			foreach ($old_products as $product) {
				$this->deleteOrderProduct($product['order_product_id']);
			}
		} // if



		$this->log("<== updateDocument()",2);
		return "";
	} // updateDocument()


	/**
	 * Обновляет документ
	 */
	private function parseDocumentRequisite($xml, &$doc) {
		$this->log("==> parseDocumentRequisite()",2);

		foreach ($xml->ЗначениеРеквизита as $requisite) {
			// обрабатываем только товары
			$name 	= (string)$requisite->Наименование;
			$value 	= (string)$requisite->Значение;
			$this->log("> Реквизит документа: " . $name. " = " . $value,2);
			switch ($name){
				case 'Номер по 1С':
					$doc['invoice_no'] = $value;
				break;
				case 'Дата по 1С':
					$doc['datetime'] = $value;
				break;
				case 'Проведен':
					$doc['posted'] = $value;
				break;
				case 'Статус заказа':
					$doc['status'] = $value;
				break;
				default:
			}
		}
		$this->log("<== parseDocumentRequisite()",2);
	} // parseDocumentRequisite()


	/**
	 * Контрагент
	 */
	private function parseDocumentCustomer($xml, &$doc) {
		$this->log("==> parseDocumentCustomer()",2);

		$error = "";
		if (!$xml) return "Нет данных в XML";

		$doc['customer_id']	= 0;
		$doc['address_id']	= 0;

		$customer_name	= (string)$xml->Контрагент->Наименование;
		$customer_name_split	= explode(" ", $customer_name);
		//$this->log($customer_name_split,2);
		$lastname				= isset($customer_name_split[0]) ? $customer_name_split[0] : "";
		$firstname				= isset($customer_name_split[1]) ? $customer_name_split[1] : "";

		// поиск покупателя по имени получателя
		if (!$doc['customer_id']) {
			$query = $this->query("SELECT `address_id`,`customer_id` FROM `" . DB_PREFIX . "address` WHERE `firstname` = '" . $this->db->escape($firstname) . "' AND `lastname` = '" . $this->db->escape($lastname) . "'");
			if ($query->num_rows) {
				$doc['customer_id'] = $query->row['customer_id'];
				$doc['address_id'] = $query->row['address_id'];
			}
		}

		// поиск покупателя по имени
		if (!$doc['customer_id']) {
			$query = $this->query("SELECT `customer_id` FROM `" . DB_PREFIX . "customer` WHERE `firstname` = '" . $this->db->escape($firstname) . "' AND `lastname` = '" . $this->db->escape($lastname) . "'");
			if ($query->num_rows) {
				$doc['customer_id'] = $query->row['customer_id'];
			}
		}

		if (!$doc['customer_id']) {
			$error = "Покупатель '" . $customer_name . "' не найден в базе";
		}
		$this->log("<== parseDocumentCustomer()",2);

		return $error;
	} // parseDocumentCustomer()


	/**
	 * Товары
	 */
	private function parseDocumentProducts($xml, &$doc) {

		$this->log("==> Начало чтения товаров документа", 2);

		$error = "";
		if (!$xml) return "Нет данных в XML";

		foreach ($xml->Товар as $product) {
			$ids		= explode("#", (string)$product->Ид);
			//$this->log($ids,2);
			if (!$ids) {
				return false;
			}

			$data = array();

			if ($product->Наименование) {
				$data['name'] = (string)$product->Наименование;
			}

			if (isset($ids[0])) {
				$data['product_cml_id'] = $ids[0];
				$data['product_id'] = $this->getProductIdByCML($ids[0]);
				if (!$data['product_id'])
					return "Товар '" . $data['name'] . "' не найден в базе по Ид '" . $ids[0] . "'";
			} else {
				return "Товар '" . $data['name'] . "' не может быть найден в базе по пустому Ид";
			}

			if (isset($ids[1])) {
				$data['product_feature_cml_id'] = $ids[1];
				$data['product_feature_id'] = $this->getProductFeatureId($ids[1]);
				if (!$data['product_feature_id'])
					return "Характеристика товара '" . $data['name'] . "' не найдена в базе по Ид '" . $ids[1] . "'";
			} else {
				$data['product_feature_id'] = 0;
			}

			if ($product->Артикул) {
				$data['sku'] = (string)$product->Артикул;
				$data['model'] = (string)$product->Артикул;
			}
			if ($product->БазоваяЕдиница) {
				$data['unit0'] = array(
					'code'		=> $product->БазоваяЕдиница->Наименование['Код'],
					'name'		=> $product->БазоваяЕдиница->Наименование['НаименованиеПолное'],
					'eng'		=> $product->БазоваяЕдиница->Наименование['МеждународноеСокращение']
				);
			}
			if ($product->ЦенаЗаЕдиницу) {
				$data['price'] = (float)$product->ЦенаЗаЕдиницу;
			}
			if ($product->Количество) {
				$data['quantity'] = (float)$product->Количество;
			}
			if ($product->Сумма) {
				$data['total'] = (float)$product->Сумма;
				// налог временно нулевой
				$data['tax'] = 0;
			}
			if ($product->Единица) {
				$data['unit'] = array(
					'unit_id'	=> $this->getUnitId((string)$product->Единица),
					'ratio'		=> (string)$product->Коэффициент
				);

			}

			$doc['products'][] = $data;
		}

		$this->log("<== Завершено чтение товаров документа", 2);

		return $error;
	} // parseDocumentProducts()


	/**
	 * Разбор классификатора
	 */
	private function parseClassifier($xml, &$error) {

		$this->log("==> Начато чтение классификатора", 2);

		$data = array();
		$data['id']				= (string)$xml->Ид;
		$data['name']			= (string)$xml->Наименование;
		$this->setStore($data['name']);

		// Организация
		if ($xml->Владелец) {
			$data['owner']			= $this->parseOwner($xml->Владелец);
			$this->log("Организация успешно прочитана", 2);
			unset($xml->Владелец);
		}

		if ($xml->ТипыЦен) {
			$this->log("==>> Чтение типов цен из классификатора (CML v2.09)", 2);
			$data['price_types'] = $this->parsePriceType($xml->ТипыЦен, $error);
			if ($error) {
				return $data;
			} else {
				unset($xml->ТипыЦен);
				$this->log("<<== Типы цен из классификатора успешно загружены", 2);
			}
		}

		if ($xml->Склады) {
			$this->log("==>> Загрузка складов из классификатора (CML v2.09)", 2);
			$this->parseWarehouses($xml->Склады);
			unset($xml->Склады);
			$this->log("<<== Склады из классификатора загружены",2);
		}

		if ($xml->ЕдиницыИзмерения) {
			$this->log("==>> Загрузка единиц измерений из классификатор (CML v2.09)",2);
			$this->parseUnits($xml->ЕдиницыИзмерения);
			unset($xml->ЕдиницыИзмерения);
			$this->log("<<=== Единицы измерения из классификатора загружены",2);
		}

		if ($xml->Свойства) {
			$this->log("==>> Загрузка свойств из классификатора",2);
			$data['attributes']		= $this->parseClassifierAttributes($xml->Свойства);
			//unset($xml->Свойства);
			$this->log("<<=== Свойства из классификатора загружены",2);
		}

		if ($xml->Группы) {
			$this->log("==>> Загрузка категорий из классификатора",2);
			$this->parseCategories($xml->Группы, 0, $data);
			unset($xml->Группы);
			$this->log("<<== Категории из классификатора загружены",2);
		}

		$this->log("<== Завершено чтение классификатора", 2);
		return $data;

	} // parseClassifier()


	/**
	 * Разбор документа
	 */
	private function parseDocument($xml) {

		$cml_id			= (string)$xml->Ид;
		$order_id		= (string)$xml->Номер;

		$this->log("[i] Загрузка документа: Заказ #" . $order_id . ", Ид '" . $cml_id . "'");

		$doc = array(
			'order_id'		=> $order_id,
			'date'			=> (string)$xml->Дата,
			'time'			=> (string)$xml->Время,
			'currency'		=> (string)$xml->Валюта,
			'total'			=> (float)$xml->Сумма,
			'doc_type'		=> (string)$xml->ХозОперация
		);

		$error = $this->parseDocumentCustomer($xml->Контрагенты, $doc);
		if ($error)
			return $error;

		$error = $this->parseDocumentProducts($xml->Товары, $doc);
		if ($error)
			return $error;

		$this->parseDocumentRequisite($xml->ЗначенияРеквизитов, $doc);

		$this->load->model('sale/order');
		$order = $this->model_sale_order->getOrder($order_id);
		if ($order) {
			$products = $this->model_sale_order->getOrderProducts($order_id);
		} else {
			return "Заказ #" . $doc['order_id'] . " не найден в базе";
		}

		$error = $this->updateDocument($doc, $order, $products);
		if ($error)
			return $error;

		return "";
	} // parseDocument()


	/**
	 * Импорт файла
	 */
	public function importFile($importFile, $type) {

		// Функция будет сама определять что за файл загружается
		$this->log("-------------------- НАЧАТА ЗАГРУЗКА ДАННЫХ --------------------");
		$this->log("[i] Всего доступно памяти: " . sprintf("%.3f", memory_get_peak_usage() / 1024 / 1024) . " Mb",2);
		$this->log("[i] Начинается чтение XML",2);

        // Записываем единое текущее время обновления для запросов в базе данных
		$this->NOW = date('Y-m-d H:i:s');

		// Определение дополнительных полей
		$this->defineAdditionalFields();

		// Читаем XML
		libxml_use_internal_errors(true);
		$this->log($importFile,2);
		$xml = @simplexml_load_file($importFile);
		if (!$xml) {
			foreach(libxml_get_errors() as $error) {
				$this->log($error->message);
			}
			return "Файл не является стандартом XML, подробности в журнале";
		}
		$this->log("XML успешно прочитан",2);

		// Файл стандарта Commerce ML
		$error = $this->checkCML($xml);
		if ($error)	return $error;

		// IMPORT.XML, OFFERS.XML
		if ($xml->Классификатор) {
			$classifier = $this->parseClassifier($xml->Классификатор, $error);
			if ($error) return $error;
			unset($xml->Классификатор);
		} else {
			// CML 2.08 + Битрикс
			$classifier = array();
		}

		if ($xml->Каталог) {
			//$this->clearLog();

			// Запишем в лог дату и время начала обмена

			$this->log("-------------------- ЗАГРУЗКА КАТАЛОГА --------------------",2);
			if (!isset($classifier)) {
				$this->log("[i] Классификатор отсутствует! Все товары будут загружены в магазин по умолчанию!");
			}

			$error = $this->parseDirectory($xml->Каталог, $classifier);
			if ($error) return "Ошибка загрузки каталога";

			unset($xml->Каталог);
		}

		// OFFERS.XML
		if ($xml->ПакетПредложений) {
			$this->log("-------------------- ЗАГРУЗКА ПАКЕТА ПРЕДЛОЖЕНИЙ --------------------", 2);

			// Пакет предложений
			$error = $this->parseOffersPack($xml->ПакетПредложений);
			if ($error)	return "Ошибка загрузки пакета предложений\n" . $error;
			unset($xml->ПакетПредложений);

			// После загрузки пакета предложений формируем SEO
			//$this->seoGenerate();

		}

		// ORDERS.XML
		if ($xml->Документ) {
			$this->log("-------------------- ЗАГРУЗКА ДОКУМЕНТОВ --------------------", 2);

			$this->clearLog();

			// Документ (заказ)
			foreach ($xml->Документ as $doc) {
				$error = $this->parseDocument($doc);
				if ($error)	return $error;
			}
			unset($xml->Документ);
		}
		else {
			$this->log("[i] Не обработанные данные XML", 2);
			$this->log($xml,2);
		}
		$this->log("-------------------- ЗАВЕРШЕНА ЗАГРУЗКА ДАННЫХ --------------------");
		return "";
	}


	/**
	 * Устанавливает обновления
	 */
	public function checkUpdates($settings) {

		$old_version = $settings['exchange1c_version'];
		$version = $old_version;
		$message = "";
		$this->defineAdditionalFields();

		if ($old_version == '1.6.2.b9') {
			$version = $this->update1_6_2_b10($version, $message);
		}
		if ($old_version == '1.6.2.b10') {
			$version = $this->update1_6_2_b11($version, $message);
		}


		if ($old_version != $version) {
			$this->setEvents();
			$settings['exchange1c_version'] = $version;
			$this->model_setting_setting->editSetting('exchange1c', $settings);
//		} else {
//			$message = "В обновлении не нуждается";
		}
		return $message;

	} // checkUpdates()


	/**
	 * Устанавливает обновления
	 */
	private function update1_6_2_b10($version, &$message) {

		$result = 1; // включено обновление
		$new_version = '1.6.2.b10';
		$message .= ($message ? "<br />" : "") . "Устанавливаются обновления до версии " . $new_version . "...<br />";
		//$this->db->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `price_type_id` INT( 6 ) NOT NULL DEFAULT 0 AFTER  `order_status_id`");

		if (!isset($this->TAB_FIELDS['order']['payment_inn'])) {
			if ($result) {
				$result = @$this->db->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `payment_inn` VARCHAR( 12 ) NOT NULL DEFAULT '' AFTER `payment_company`");
				$message .= ($result ? "Успешно добавлено поле " : "Ошибка при добавлении поля ") . "payment_inn в таблицу order<br />";
			}
		}

		if (!isset($this->TAB_FIELDS['order']['shipping_inn'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `shipping_inn` VARCHAR( 12 ) NOT NULL DEFAULT '' AFTER `shipping_company`");
				$message .= ($result ? "Успешно добавлено поле " : "Ошибка при добавлении поля ") . "shipping_inn в таблицу order<br />";
			}
		}

		if (!isset($this->TAB_FIELDS['customer']['patronymic'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "customer` ADD  `patronymic` VARCHAR( 64 ) NOT NULL DEFAULT '' AFTER `lastname`");
				$message .= ($result ? "Успешно добавлено поле " : "Ошибка при добавлении поля ") . "patronymic в таблицу customer<br />";
			}
		}

		if (!isset($this->TAB_FIELDS['order']['patronymic'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `patronymic` VARCHAR( 64 ) NOT NULL DEFAULT '' AFTER `lastname`");
				$message .= ($result ? "Успешно добавлено поле " : "Ошибка при добавлении поля ") . "patronymic в таблицу order<br />";
			}
		}

		if (!isset($this->TAB_FIELDS['order']['payment_patronymic'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `payment_patronymic` VARCHAR( 64 ) NOT NULL DEFAULT '' AFTER `payment_lastname`");
				$message .= ($result ? "Успешно добавлено поле " : "Ошибка при добавлении поля ") . "payment_patronymic в таблицу order<br />";
			}
		}

		if (!isset($this->TAB_FIELDS['order']['shipping_patronymic'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `shipping_patronymic` VARCHAR( 64 ) NOT NULL DEFAULT '' AFTER `shipping_lastname`");
				$message .= ($result ? "Успешно добавлено поле " : "Ошибка при добавлении поля ") . "shipping_patronymic в таблицу order<br />";
			}
		}

		//return 	'1.6.2.b10';
		if ($result) {
			$version = $new_version;
			$message .= "Обновление прошло успешно";
		} else {
			$message .= "Обновление не произведено!";
		}
		return 	$version;

	} // update1_6_2_b10()

	/**
	 * Устанавливает обновления
	 */
	private function update1_6_2_b11($version, &$message) {

		$result = 1; // включено обновление
		$new_version = '1.6.2.b11';
		$message .= ($message ? "<br />" : "") . "Устанавливаются обновления до версии " . $new_version . "...<br />";
		//$this->db->query("ALTER TABLE  `" . DB_PREFIX . "order` ADD  `price_type_id` INT( 6 ) NOT NULL DEFAULT 0 AFTER  `order_status_id`");

		if (isset($this->TAB_FIELDS['cart']['product_feature_id'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "cart` DROP COLUMN `product_feature_id`");
				$message .= ($result ? "Успешно удалено поле " : "Ошибка при удалении поля ") . "'product_feature_id' в таблице 'cart'<br />";
			}
		}

		if (isset($this->TAB_FIELDS['cart']['unit_id'])) {
			if ($result) {
				$result = @$this->query("ALTER TABLE  `" . DB_PREFIX . "cart` DROP COLUMN `unit_id`");
				$message .= ($result ? "Успешно удалено поле " : "Ошибка при удалении поля ") . "'unit_id' в таблице 'cart'<br />";
			}
		}

		//return 	'1.6.2.b10';
		if ($result) {
			$version = $new_version;
			$message .= "Обновление прошло успешно";
		} else {
			$message .= "Обновление не произведено!";
		}
		return 	$version;

	} // update1_6_2_b11()

}

