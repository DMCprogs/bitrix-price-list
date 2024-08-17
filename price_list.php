<?
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("NO_AGENT_CHECK", true);

use Bitrix\Catalog\PriceTable;
use Bitrix\Iblock\ORM\Query;
use Bitrix\Sale\StoreProductTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\UserGroupTable;

if (isset($argv)) {
	parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

if (!$_GET["access"] == "jgkilaerSEFJKLr9rjq2r") {
	die('ACCESS DENIED!');
}
$_SERVER["DOCUMENT_ROOT"] = dirname(dirname(__FILE__));

set_time_limit(0);
ini_set('memory_limit', '1024M');

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

define("OPT_GROUP", 8);
define("DEALER_GROUP", 7);

require_once "price_list_functions.php";

List ($elements, $arStores) = getAllData();

// ------------------------------------------


List($dailyXml, $weeklyXml) = getDailyWeeklyXml();

$emails = [];
$emails = getUsers($dailyXml, $emails);

// Если понедельник то отправляем еженедельный
if (date('D') === 'Mon') {
	$emails = getUsers($weeklyXml, $emails);
}

if ($_GET["DEBUG"] == "Y") {
	$emails = [67 => ["dimitri.galtzov@yandex.ru"]];
	// matrix-elf@yandex.ru
}

$groups = getGroups(array_keys($emails));
$cities = getCities(array_keys($emails));


foreach ($emails as $id => $userEmails) {
	$path = generateFile($elements, $arStores, $groups[$id] ?? [], $cities[$id]);


	// $path = $filePath;
	// if (in_array(DEALER_GROUP, $groups[$id])) {
	// 	$path = $filePathDealer;
	// }
	// if (in_array(OPR_GROUP, $groups[$id])) {
	// 	$path = $filePathOpt;
	// }

	foreach ($userEmails as $key => $email) {
		CEvent::Send(
			"SALT_SEND_PRICE_LIST", // mail event name
			SITE_ID,
			[
				"EMAIL_TO" => $email,
				"DATE" => date("d.m.Y"),
				"REMOVE_TEXT" => "Вы можете <a href=//paritet-sib.ru/personal/>отписаться от рассылки в личном кабинете</a>"
			],
			"Y",
			"",
			[$path]
		);
	}

}
echo "sended email to ".count($emails)." users \n ";

CMain::FinalActions();