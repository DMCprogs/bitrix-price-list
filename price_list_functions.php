<? if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Catalog\PriceTable;
use Bitrix\Iblock\ORM\Query;
use Bitrix\Sale\StoreProductTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserGroupTable;

function getAllData() {
	// ------------------------------------------
	 // Создаем переменную для хранения отладочной информации
	CModule::IncludeModule("iblock");
	CModule::IncludeModule("catalog");
	CModule::IncludeModule("sale");

	// SKU
	$elements = \Bitrix\Iblock\Elements\ElementSkuTable::query()
		->setSelect([
			"ID",
			"NAME",
			"VOLUME" => "OBEM.VALUE",
			"CATALOG_ELEMENT_ID" => "CML2_LINK.VALUE",
			// "BRAND" => "CML2_LINK.ELEMENT.BREND.VALUE", // slow
			"ARTICLE" => "CML2_ARTICLE.VALUE",
		])
		->where('ACTIVE', 'Y')
		// ->where('CML2_LINK.ACTIVE', 'Y')
		->exec()->fetchAll();

	$brands4sku = \Bitrix\Iblock\Elements\ElementCatalogTable::query()
		->setSelect([
			"ID",
			"BRAND" => "BREND.VALUE"
		])
		->where('ACTIVE', 'Y')
		->where('ID', 'in', array_unique(array_column($elements, "CATALOG_ELEMENT_ID")))
		->exec()->fetchAll();

	$brands4sku = array_combine(
		array_column($brands4sku, "ID"),
		$brands4sku
	);

	foreach ($elements as &$sku) {
		$sku["BRAND"] = $brands4sku[$sku["CATALOG_ELEMENT_ID"]]["BRAND"];
	}
	unset($sku);
	unset($brands4sku);

	// not sku
	$soloElements = \Bitrix\Iblock\Elements\ElementCatalogTable::query()
		->setSelect([
			"ID",
			"NAME",
			"VOLUME" => "OBEM.VALUE",
			"ARTICLE" => "CML2_ARTICLE.VALUE",
			"BRAND" => "BREND.VALUE"
		])
		->where('ACTIVE', 'Y')
		->whereNot('ID', 'in', array_unique(array_column($elements, "CATALOG_ELEMENT_ID")))
		->exec()->fetchAll();

	$elements = array_merge($elements, $soloElements);
	echo "Number of non-SKU elements: " . count($soloElements) . "\n";
	unset($soloElements);

	$pricesTypes = \Bitrix\Catalog\GroupTable::query()
		->setSelect(["ID", "XML_ID", "NAME"])
		// ->where("BASE", "Y")
		->where(
			Query::filter()
				->logic("or")
				->where("NAME", "Оптовая 9")
				->where("NAME", "Дилерская")
				->where("BASE", "Y")
				->where("NAME", "интернет-розница")
		)
		->exec()->fetchAll();

	$pricesTypes = array_combine(
		array_column($pricesTypes, "ID"),
		$pricesTypes
	);

	$pricesTmp = PriceTable::query()
		->setSelect(["PRODUCT_ID", "PRICE","CATALOG_GROUP_ID"])
		->where("PRODUCT_ID", "in", array_unique(array_column($elements, "ID")))
		->where("CATALOG_GROUP_ID", "in", array_column($pricesTypes, "ID"))
		->exec()->fetchAll();

	$prices = array_keys(array_unique(array_column($pricesTmp, "PRODUCT_ID")));

	foreach ($pricesTmp as $price) {
		$prices[$price["PRODUCT_ID"]][$pricesTypes[$price["CATALOG_GROUP_ID"]]["NAME"]] = $price["PRICE"];
	}
	unset($pricesTmp);

	$arStores = \Bitrix\Catalog\StoreTable::getList()->fetchAll();
	$arStores = array_combine(
		array_column($arStores, "ID"),
		$arStores
	);
	$rsAmounts = StoreProductTable::getList([
		"filter" => ["PRODUCT_ID" => array_unique(array_column($elements, "ID"))]
	]);
	$amounts = [];
	while ($row = $rsAmounts->fetch()) {
		$amounts[$row["PRODUCT_ID"]][$row["STORE_ID"]] = (string) $row["AMOUNT"];
	}
	unset($rsAmounts);
	// unset($arStores);

	foreach ($elements as &$product) {
		$pid = $product["ID"];
		$product = [
			// "ID" => $pid,
			"NAME" => trim($product["NAME"]),
			"BRAND" => $product["BRAND"],
			"PRICE" => $prices[$product["ID"]]["BASE"],
			"VOLUME" => $product["VOLUME"],
			"ARTICLE" => $product["ARTICLE"],
			// "QUANTITY" => $amounts[$product["ID"]],
		];

		foreach ($arStores as $sid => $store) {
			$product["STORES"][$store["ID"]] =  $amounts[$pid][$sid] ?? "0";
		}

		$product["PRICE_OPT"] = $prices[$pid]["Оптовая 9"];
		$product["PRICE_DIALER"] = $prices[$pid]["Дилерская"];
		if ($prices[$pid]["интернет-розница"] > 0) {
			$product["PRICE"] = $prices[$pid]["интернет-розница"];
		}
	}

	unset($product);
	unset($amounts);
	unset($prices);


	echo "Number of SKU elements: " . count($elements) . "\n";
   

	 // Отладочный вывод для проверки результата
	 if (empty($elements)) {
        echo "Warning: No elements retrieved!\n";
    }

	return [$elements, $arStores];
}



function generateFile($elements, $arStores, $groups, $stores) {
    echo "Generating file...\n";
    $hashData = print_r($groups, true) . " " . print_r($stores, true);
    $hash = hash("md5", $hashData);

    $filePath = $_SERVER["DOCUMENT_ROOT"] . '/upload/price.list/' . date("Y-m-d") . '-' . $hash . '.csv';
    echo "File path: $filePath\n";

    if (file_exists($filePath)) {
        echo "File already exists. Returning existing file path.\n";
        return $filePath;
    }

    $firstLine = [
        "NAME" => "Наименование",
        "BRAND" => "Бренд",
        "PRICE" => "Цена р.",
        "VOLUME" => "Объем",
        "ARTICLE" => "Артикул",
    ];

    $usedStores = [];
    if (is_array($stores) && !empty($stores)) {
        foreach ($arStores as $store) {
            if (in_array($store["ADDRESS"], $stores)) {
                $usedStores[] = $store["ID"];
                $firstLine[] =  $store["TITLE"];
            }
        }
    } else {
        $usedStores = array_column($arStores, "ID");
        foreach ($arStores as $store) {
            $firstLine[] =  $store["TITLE"];
        }
    }

    echo "Opening file for writing...\n";
    $fp = fopen($filePath, 'w+');
	fwrite($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
    if ($fp === false) {
        echo "Failed to open file for writing: $filePath\n";
    } else {
        echo "File opened successfully.\n";
    }

    fputcsv($fp, $firstLine, ";");
    echo "Header written to file.\n";

    foreach ($elements as $key => $item) {
        $arRow = [
            "NAME" => $item["NAME"],
            "BRAND" => $item["BRAND"],
            "PRICE" => $item["PRICE"],
            "VOLUME" => $item["VOLUME"],
            "ARTICLE" => $item["ARTICLE"],
        ];

        if (in_array(DEALER_GROUP, $groups)) {
            $arRow["PRICE"] = $item["PRICE_DIALER"];
        }
        if (in_array(OPT_GROUP, $groups)) {
            $arRow["PRICE"] = $item["PRICE_OPT"];
        }

        foreach ($usedStores as $storeId) {
            $arRow["STORE_" . $storeId] = $item["STORES"][$storeId];
        }

        if (fputcsv($fp, $arRow, ";") === false) {
            echo "Failed to write row for element ID: {$item['ID']}\n";
        } else {
            echo "Row for element ID: {$item['ID']} written successfully.\n";
        }
    }

    fclose($fp);
    echo "File closed after writing.\n";

    $data = file_get_contents($filePath);
    echo "File contents preview:\n";
    echo mb_substr($data, 0, 500) . "\n"; // Показать первые 500 символов

    echo "Price list generated successfully.\n";
    echo "Number of elements to write: " . count($elements) . "\n";
    foreach ($elements as $key => $item) {
        echo "Element $key: " . print_r($item, true) . "\n";
    }

    return $filePath;
}


function getDailyWeeklyXml() {
	$rsEnum = CUserFieldEnum::GetList(
		["SORT" => "ASC"],
		["USER_FIELD_NAME" => "UF_PRICE_LIST"]
	);

	$enums = [];
	while ($arEnum = $rsEnum->Fetch()) {
		$enums[$arEnum["XML_ID"]] = $arEnum["ID"];
	}
	$dailyXml = $enums["DAILY"];
	$weeklyXml = $enums["WEEKLY"];

	return [$dailyXml, $weeklyXml];
}

function getUsers($filterXml, &$emails) {
	$rsUsers = CUser::GetList(
		($by = "id"),
		($order = "desc"),
		["UF_PRICE_LIST" => $filterXml],
		["SELECT" => ["UF_PRICE_LIST", "UF_ADDITIONAL_EMAIL"]]
	);
	while ($user = $rsUsers->Fetch()) {
		$emails[$user["ID"]][] = $user["EMAIL"];
		foreach ($user["UF_ADDITIONAL_EMAIL"] as $key => $value) {
			$emails[$user["ID"]][] = $value;
		}
	}

	return $emails;
}

function getGroups($userIds) {
	$groupsTmp = UserGroupTable::query()
		->where("USER_ID", "in", $userIds)
		->where("GROUP_ID", "in", [OPT_GROUP, DEALER_GROUP])
		->setSelect(["USER_ID", "GROUP_ID"])
		->exec()->fetchAll();

	$groups = [];
	foreach ($groupsTmp as $user) {
		$groups[$user["USER_ID"]][] = $user["GROUP_ID"];
	}
	unset($groupsTmp);
	return $groups;
}

function getCities($userIds) {
	$cities = [];

	$rsEnum = CUserFieldEnum::GetList(
		["SORT" => "ASC"],
		["USER_FIELD_NAME" => "UF_CITY_FOR_STORES2"]
	);

	$citiesEnum = [];
	while ($arEnum = $rsEnum->Fetch()) {
		$citiesEnum[$arEnum["ID"]] = $arEnum["VALUE"];
	}


	$rsUsers = CUser::GetList(
		($by = "ID"),
		($order = "desc"),
		[
			"ID" => implode("|", $userIds),
		],
		[
			"FIELDS" => ["ID"],
			"SELECT" => ["UF_CITY_FOR_STORES2"]
		]
	);

	while ($user = $rsUsers->fetch()) {
		$cities[$user["ID"]] = [];
		if (!empty($user["UF_CITY_FOR_STORES2"])) {
			foreach ($user["UF_CITY_FOR_STORES2"] as $cityId) {
				$cities[$user["ID"]][$cityId] = $citiesEnum[$cityId];
			}
		}
	}

	return $cities;
}