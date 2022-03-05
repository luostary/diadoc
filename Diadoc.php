<?php

namespace app\components;

use app\components\keyStorage\KeyStorage;
use app\components\proto\classes\Box;
use app\components\proto\classes\Counteragent;
use app\components\proto\classes\CounteragentList;
use app\components\proto\classes\Document;
use app\components\proto\classes\DocumentList;
use app\components\proto\classes\DocumentTypesResponseV2;
use app\components\proto\classes\DraftToSend;
use app\components\proto\classes\Message;
use app\components\proto\classes\MessageToPost;
use app\components\proto\classes\Organization;
use app\components\proto\classes\OrganizationList;
use app\components\proto\classes\Template;
use app\components\proto\classes\TemplateToPost;
use app\models\base\Act;
use app\models\base\ActKs3;
use app\models\base\ChangeLog;
use app\models\DiadocSetting;
use app\modules\contract\modules\prints\controllers\A000Controller;
use app\modules\contract\modules\prints\controllers\A001Controller;
use app\modules\contract\modules\prints\controllers\A002Controller;
use app\modules\contract\modules\prints\PrintModule;
use app\modules\system\modules\user\models\User;
use yii\base\Component;
use yii\helpers\HtmlPurifier;
use yii\helpers\Json;
use yii\httpclient\Exception;
use yii\web\Application;
use Yii;

/**
 * Class Diadoc
 * @package app\components
 *
 */
class Diadoc extends Component
{
    /**
     * Установка утилиты protoc
     * apt install -y protobuf-compiler
     *
     * Проверка правильности установки
     * protoc --version  # Ensure compiler version is 3+
     *
     * Создание классов из прото файлов
     * php ./vendor/protobuf-php/protobuf-plugin/bin/protobuf --include-descriptors -i . -o ./components/proto/classes/ ./components/proto/TemplateToPost.proto
     */
    static $error;
    static $errorCode;
    static $response;
    public $token;
    static $params = [];
    static $settings;
    static $baseUrl = 'https://diadoc-api.kontur.ru';
    static $externalUrlManager = null;
    const COMMON_ERROR = 'Диадок не подключен';

    const RecipientResponseStatus_RecipientResponseStatusUnknown = 0;
    const RecipientResponseStatus_RecipientResponseStatusNotAcceptable = 1;
    const RecipientResponseStatus_WithRecipientSignature = 3;
    const RecipientResponseStatus_RecipientSignatureRequestRejected = 4;

    const DOC_FLOW_STATUS_CANCELED = 'Аннулирован';
    const DOC_FLOW_STATUS_PARTNER_SIGNED = 'Подписан контрагентом';
    const DOC_FLOW_STATUS_PENDING_CANCELLATION = 'Ожидается аннулирование';
    const DOC_FLOW_STATUS_PARTNER_REJECTED = 'Контрагент отказал в подписи';
    const DOC_FLOW_STATUS_DOCUMENT_WAITING_CREATE = 'Ожидается создание документа';
    const DOC_FLOW_STATUS_DOCUMENT_NEED_CREATE = 'Требуется создать документ';
    const DOC_FLOW_STATUS_DOCUMENT_PROCESSED = 'Обработан';
    const DOC_FLOW_STATUS_DOCUMENT_NEED_SIGN = 'Требуется подписать и отправить';
    const DOC_FLOW_STATUS_DOCUMENT_SIGNED = 'Подписан';
    const DOC_FLOW_STATUS_DOCUMENT_DONE = 'Документооборот завершен';

    public function init()
    {

    }

    public function __construct()
    {
        self::$externalUrlManager = new \yii\web\UrlManager([
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'baseUrl' => self::$baseUrl,
        ]);
        self::$settings = DiadocSetting::find()->where(['is_active' => 1])->one();
        if(self::$settings) {
            if (!self::hasValidToken() || !self::auth()) {
                $this->authorization();
                $this->setToken();
            }
        }
    }

    public static function find()
    {
        self::$settings = DiadocSetting::find()->where(['is_active' => 1])->one();
        if (self::$settings) {
            return new self();
        }
    }

    public static function check(): bool
    {
        return self::hasValidToken() && self::auth();
    }

    /** Авторизация и сохранение полученного токена */
    public function authorization()
    {
        $ks3SyncKey = (integer)trim(HtmlPurifier::process(KeyStorage::getKeyByAlias('KS3SyncEDSStatus')));

        /** @var  DiadocSetting $settings */
        $settings = self::$settings;
        if (!$settings) {
            $this->throwException(\Yii::t('eds', 'Check the settings for a diadoc API connection'));
        }

        if (!$ks3SyncKey) {
            $this->throwException(\Yii::t('eds', 'Not specified status for synchronization with EDS'));
        }

        self::$params = [
            'url' => self::$externalUrlManager->createAbsoluteUrl([
                $settings->url_auth,
                'type' => 'password',
            ]),
            'requestType' => 'POST',
            'httpHeader' => [
                "Authorization: DiadocAuth ddauth_api_client_id=" . $settings->diadoc_client_id,
                "Content-Type: application/json",
            ],
            'postFields' => Json::encode([
                'login' => $settings->login,
                'password' => $settings->password,
            ]),
        ];
        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * Проверка валидности токена
     * @return bool
     */
    public static function hasValidToken()
    {
        return (self::$settings && self::$settings->token && strlen(self::$settings->token) == 172);
    }

    /**
     * Проверка авторизации в системе Диадок на примере получения моих организаций
     * @return bool
     */
    public static function auth()
    {
        $result = false;
        if(Diadoc::hasValidToken()) {
            $urlManager = new \yii\web\UrlManager([
                'enablePrettyUrl' => true,
                'showScriptName' => false,
                'baseUrl' => self::$baseUrl,
            ]);
            self::$params['url'] = $urlManager->createAbsoluteUrl([
                '/GetMyOrganizations',
                'autoRegister' => 'false'
            ]);
            self::$params['requestType'] = 'GET';
            self::$params['httpHeader'] = [
                "Authorization: DiadocAuth ddauth_api_client_id=" . self::$settings->diadoc_client_id . ", ddauth_token=" . self::$settings->token,
            ];
            $response = self::curlRequest();

            try {
                $organizations = OrganizationList::fromStream($response);
                $result = true;
            } catch (\Exception $e) {}
        }
        return $result;
    }

    /**
     * Получение токена
     * @return bool
     * @throws \yii\console\Exception
     */
    public function setToken()
    {
        if(!self::$response || strlen(self::$response) != 172) {
            throw new \yii\console\Exception('Не удается получить авторизационный token от системы Диадок', '504');
        }
        self::$settings->token = self::$response;
        self::$settings->save(false);
        self::$params['httpHeader'] = [
            "Authorization: DiadocAuth ddauth_api_client_id=" . self::$settings->diadoc_client_id . ", ddauth_token=" . self::$settings->token,
        ];
        return true;
    }

    /** Получение токена
     * @return mixed|null
     */
    public function getToken()
    {
        return self::$settings->token;
    }

    /** Связан ли контрагент с моей организацией
     * @param integer $counterAgentInn
     * @return bool
     */
    public function checkRelationshipOrganizations($counterAgentInn)
    {
        /** @var OrganizationList $organizationList */
        $organizationList = self::getMyOrganizationsV2();
        $organizationDiaDocId = $organizationList->getOrganizationsList()[0]->getOrgId();

        /** @var Organization $partnerDiaDoc */
        $partnerDiaDoc = self::getOrganization('inn', $counterAgentInn);
        $partnerDiaDocId = $partnerDiaDoc->getOrgId();
        /** @var Counteragent $counterAgent */
        $counterAgent = self::getCounteragent($organizationDiaDocId, $partnerDiaDocId);
        return (
            $counterAgent->getCurrentStatus()->name() == 'IsMyCounteragent' &&
            $counterAgent->getCurrentStatus()->value() == 1);
    }

    /**
     * Список контрагентов относящихся к организации
     * Docs http://api-docs.diadoc.ru/ru/latest/http/GetCounteragents.html
     */
    public function getCounterAgents(string $myOrgId = null, string $counteragentStatus = null, $afterIndexKey = 0)
    {
        if ($myOrgId == null) {
            die('Не указан ИД организации');
        }
        $urlParams = [
            '/V2/GetCounteragents',
            'myOrgId' => $myOrgId,
            'afterIndexKey' => $afterIndexKey,
        ];
        if ($counteragentStatus) {
            $urlParams['counteragentStatus'] = $counteragentStatus;
        }
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl($urlParams);
        self::$params['requestType'] = 'GET';
        self::$params['requestClass'] = CounteragentList::class;
        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetCounteragents.html?#v3
     * @param string|null $myOrgId
     * @param string|null $counteragentStatus
     *
     * @return CounteragentList|\Protobuf\Message
     */
    public function getCounterAgentsV2(
        string $myOrgId = null,
        string $counteragentStatus = null,
        int $afterIndexKey = 0
    ) {
        if ($myOrgId == null) {
            die('Не указан ИД организации');
        }
        $urlParams = [
            '/V2/GetCounteragents',
            'myOrgId' => $myOrgId,
            'afterIndexKey' => $afterIndexKey,
        ];
        if ($counteragentStatus) {
            $urlParams['counteragentStatus'] = $counteragentStatus;
        }
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl($urlParams);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        return CounteragentList::fromStream(self::$response);
    }

    /**
     * Формирование документа Счет-фактура
     * @param string $boxId
     * @param integer $ks3id
     * @return bool|string|void
     * @throws \yii\base\InvalidConfigException
     */

    public function generateUniversalTransferDocument($boxId, $ks3id)
    {

        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GenerateTitleXml',
            'boxId' => $boxId,
            'documentTypeNamedId' => 'UniversalTransferDocument',
            'documentFunction' => 'СЧФДОП', // строковый идентификатор функции, уникальный в рамках типа документа
            'documentVersion' => 'utd820_05_01_01', // _Hyphen', //  – строковый идентификатор версии, уникальный в рамках функции типа документа
            'titleIndex' => 0,
        ]);

        $actKs3 = ActKs3::findOne($ks3id);
        $contract = $actKs3->contract;

        /** @var \app\models\base\Organization $partner */
        $partner = $contract->partner;

        $concatItemName = htmlentities($contract->object_description) .
            ' по объекту ' . htmlentities($contract->project_text) .
            ' по договору ' . $contract->number . ' от ' . Yii::$app->formatter->asDate($contract->dt_contract, Yii::$app->params['dateRu']) .
            ' за период с ' . Yii::$app->formatter->asDate($actKs3->period_from, Yii::$app->params['dateRu']) . ' по ' . Yii::$app->formatter->asDate($actKs3->period_to, Yii::$app->params['dateRu']);

        $params = [
            'DocumentDate' => date('d.m.Y'),
            'DocumentCreatorBase' => $contract->customer->main_accountant,
            'DocumentCreator' => $contract->customer->main_accountant,
            'DocumentNumber' => '№ ' . $actKs3->number . ' по договору ' . $contract->number,
            'Function' => 'СЧФДОП',

            // Продавец (Исполнитель)
            'executorInn' => $partner->inn,
            'executorFnsParticipantId' => $partner->diadocPartner->fns_participant_id,
            'executorOrgName' => 1,
            'executorType' => $partner->type_id,

            // Товар
            'Product' => $concatItemName,
            'Unit' => null,
            'TaxRate' => (($partner->payer_nds) ? Yii::$app->params['NDS'] . '%' : 'без НДС'),
            'Quantity' => 1,
            'Price' => 0,
            'Vat' => 0,
            'Subtotal' => 0,
            'SubtotalWithVatExcluded' => 0,

            // Подписант (Заказчик)
            'customerInn' => $contract->customer->inn,
            'customerType' => 1,
            'customerLastName' => 'Шамшин',
            'customerFirstName' => 'Владимир',
            'customerMiddleName' => 'Владимирович',
            'customerBoxId' => $boxId,
        ];
        foreach ($actKs3->actsKs2 as $item) {
            /** @var Act $item */
            if ($item->act_ks3_id == $actKs3->id) {
                $params['Price'] += $item->summ;
            }
        }

        $params['Subtotal'] = $params['Quantity'] * $params['Price'];
        if ($partner->payer_nds) {
            $params['Vat'] = round($params['Price'] * (Yii::$app->params['NDS'] / 100) / (Yii::$app->params['NDS'] / 100 + 1), 2);
            $params['SubtotalWithVatExcluded'] = $params['Price'] - $params['Vat'];
        }

        $params['Total'] = [
            'Total' => $params['Subtotal'],
            'Vat' => $params['Vat'],
            'TotalWithVatExcluded' => $params['SubtotalWithVatExcluded'],
        ];

        self::$params['requestType'] = 'POST';

        $content = (new \yii\base\View())->renderFile(Yii::getAlias('@app') . '/components/proto/xml/UniversalTransferDocument.php', ['data' => $params]);
        self::$params['postFields'] = $content;
        self::$response = $this->curlRequest();
        return self::$response;
    }
    public function generateTitleXml($boxId, $ks3id, $executorBoxId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GenerateTitleXml',
            'boxId' => $boxId,
            'documentTypeNamedId' => 'Invoice', // уникальный строковый идентификатор типа документа
            'documentFunction' => 'default', // строковый идентификатор функции, уникальный в рамках типа документа
            'documentVersion' => 'utd820_05_01_01', //  – строковый идентификатор версии, уникальный в рамках функции типа документа
            'titleIndex' => 0,
            'EditingSettingId' => '4024c006-6913-487b-bed8-5b18d58e6c77', // Отвечает за редактирование номера и даты для версии utd820_05_01_01 с функцией СЧФ
            // 'EditingSettingId' => '3c37e4ca-d28e-4dd7-bca4-a667c79e8ede', // Отвечает за редактирование номера и даты для версии utd820_05_01_01 с функцией СЧФ
        ]);
        $actKs3 = ActKs3::findOne($ks3id);
        $contract = $actKs3->contract;

        /** @var \app\models\base\Organization $partner */
        $partner = $contract->partner;
        $concatItemName = htmlentities($contract->object_description) .
            ' по объекту ' . htmlentities($contract->project_text) .
            ' по договору ' . $contract->number . ' от ' . Yii::$app->formatter->asDate($contract->dt_contract, Yii::$app->params['dateRu']) .
            ' за период с ' . Yii::$app->formatter->asDate($actKs3->period_from, Yii::$app->params['dateRu']) . ' по ' . Yii::$app->formatter->asDate($actKs3->period_to, Yii::$app->params['dateRu']);

        if (!$partner->diadocPartner || !$partner->diadocPartner->fns_participant_id) {
            throw new Exception('Идентификатор исполнителя в ФНС не найден');
        }

        $params = [
            'DocumentDate' => Yii::$app->formatter->asDate($actKs3->dt_act, Yii::$app->params['dateRu']),
            'DocumentCreatorBase' => $contract->customer->main_accountant,
            'DocumentCreator' => $contract->customer->main_accountant,
            'PaymentDocumentsXml' => null,

            // Продавец (Исполнитель)
            'executorInn' => $partner->inn,
            'executorFnsParticipantId' => $partner->diadocPartner->fns_participant_id,
            'executorOrgName' => 1,
            'executorType' => $partner->type_id,
            'executorBoxId' => $executorBoxId,

            // Товар
            'Product' => $concatItemName,
            'Unit' => null,
            'TaxRate' => (($partner->payer_nds) ? Yii::$app->params['NDS'] . '%' : 'без НДС'),
            'Quantity' => 1,
            'Price' => 0,
            'Vat' => 0,
            'Subtotal' => 0,
            'SubtotalWithVatExcluded' => 0,

            // Подписант документа СФ (Исполнитель)
            'customerInn' => $partner->inn,
            'customerType' => $partner->type_id,
            'customerLastName' => ' ',
            'customerFirstName' => ' ',
            'customerMiddleName' => ' ',
            'customerBoxId' => $boxId,
        ];
        if ($actKs3->lastAct) {
            $lastActKs2 = $actKs3->lastAct;

            $fioArray = User::ShortNameAsArray($lastActKs2->signee_name_executor);
            $params['customerLastName'] = $fioArray['last'];
            $params['customerFirstName'] = $fioArray['first'];
            $params['customerMiddleName'] = $fioArray['middle'];

            if ($lastActKs2->payment_documents) {
                $json = Json::decode($lastActKs2->payment_documents);
                if ($json['ids'] && $json['xml'] ) {
                    $params['PaymentDocumentsXml'] = $json['xml'];
                }
            }
        }

        foreach ($actKs3->actsKs2 as $key => $item) {
            /** @var Act $item */
            if ($item->act_ks3_id == $actKs3->id) {
                $params['Price'] += $item->summ;
            }

            // Документы об отгрузке
            $params['DocumentShipments'][] = [
                'Name' => 'Акт КС-2',
                'Number' => ($key == 0) ? ' п/п 1 №' . $item->number : $item->number,
                'Date' => Yii::$app->formatter->asDate($item->dt_act, Yii::$app->params['dateRu']),
            ];
        }

        $params['Subtotal'] = $params['Quantity'] * $params['Price'];
        if ($partner->payer_nds) {
            $params['Vat'] = round($params['Price'] * (Yii::$app->params['NDS'] / 100) / (Yii::$app->params['NDS'] / 100 + 1), 2);
            $params['SubtotalWithVatExcluded'] = $params['Price'] - $params['Vat'];
        }

        $params['Total'] = [
            'Total' => $params['Subtotal'],
            'Vat' => $params['Vat'],
            'TotalWithVatExcluded' => $params['SubtotalWithVatExcluded'],
        ];
        self::$params['requestType'] = 'POST';

        $view = new \yii\base\View();

        /** В расчетах эти параметры участвовали, но после расчетов мы чистим колонки 3,4 шаблона счета фактуры */
        $params['Price'] = 0;
        $params['Quantity'] = 0;

        $content = $view->renderFile(Yii::getAlias('@app') . '/components/proto/xml/Invoice.php', ['data' => $params]);

        self::$params['postFields'] = $content;

        self::$response = $this->curlRequest();

        return self::$response;
    }

    /** Найти контрагента по параметру */
    public function findCounterAgentByParam($counterAgents = [], $key, $value)
    {
        if (!$key) {
            die('Не указан ключ');
        }
        if (!$value) {
            die('Не указано значение');
        }
        /** @var Counteragent $item */
        foreach ($counterAgents as $item) {
            if ($key == 'inn' && $item->getOrganization()->getInn() == $value) {
                return $item;
            }
        }
        return null;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetMessage.html
     * @param string $boxId
     * @param string $messageId
     * @return Message|\Protobuf\Message
     */
    public function getMessage($boxId, $messageId, $entityId = null)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V5/GetMessage',
            'boxId' => $boxId,
            'messageId' => $messageId,
            'entityId' => $entityId
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        if(self::$errorCode != 200){
            return self::$error;
        }
        return Message::fromStream(self::$response);
    }

    public function getTemplate($boxId, $templateId, $entityId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetTemplate',
            'boxId' => $boxId,
            'templateId' => $templateId,
            'entityId' => $entityId
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        if(self::$errorCode != 200){
            return self::$error;
        }
        return Template::fromStream(self::$response);
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetDocument.html
     * @param string $boxId
     * @param string $messageId
     * @param string $entityId
     * @return Document|\Protobuf\Message
     */
    public function getDocument($boxId, $messageId, $entityId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V3/GetDocument',
            'boxId' => $boxId,
            'messageId' => $messageId,
            'entityId' => $entityId,
        ]);
        self::$params['requestType'] = 'GET';
        self::$params['requestClass'] = Document::class;
        self::$response = $this->curlRequest();
        return self::$response;
    }

    public function getOutboundDocuments($boxId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V3/GetDocuments',
            'boxId' => $boxId,
            'filterCategory' => 'Any.OutboundWithRecipientSignature', // все исходящие, подписанные контрагентом
        ]);
        self::$params['requestType'] = 'GET';
        self::$params['requestClass'] = DocumentList::class;
        self::$response = $this->curlRequest();
        if(self::$errorCode != 200){
            return self::$error;
        }
        return self::$response;
    }

    /**
     * Список организаций
     * https://api-docs.diadoc.ru/ru/latest/http/GetMyOrganizations.html
     */
    public function getMyOrganizations()
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetMyOrganizations',
            'autoRegister' => 'false'
        ]);
        self::$params['requestType'] = 'GET';
        self::$params['requestClass'] = OrganizationList::class;
        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetMyOrganizations.html
     * @return OrganizationList|\Protobuf\Message
     */
    public function getMyOrganizationsV2()
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetMyOrganizations',
            'autoRegister' => 'false'
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        return OrganizationList::fromStream(self::$response);
    }

    public function getHeadOrganization()
    {
        /** @var OrganizationList $organizationList */
        $organizationList = $this->getMyOrganizationsV2();
        $items = $organizationList->getOrganizationsList();
        if($items->count()) {
            /** @var Organization $item */
            foreach ($items as $item) {
                if(!$item->getIsBranch()) {
                    return $item;
                }
            }
        }
        throw new Exception('Head organization not found');
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetCounteragent.html?#v2
     * @param string $myOrgId
     * @param string $counteragentOrgId
     * @return Counteragent|\Protobuf\Message
     */
    public function getCounteragent(string $myOrgId, string $counteragentOrgId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V2/GetCounteragent',
            'myOrgId' => $myOrgId,
            'counteragentOrgId' => $counteragentOrgId,
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        return Counteragent::fromStream(self::$response);
    }

    /** Получение названия документа КС СП */
    public function getKsPrintFormName($id, $type)
    {
        switch (Yii::$app->params['projectCode']) {
            case 'a001':
                $printFormController = new A001Controller(Yii::$app->params['projectCode'],
                    new PrintModule(Yii::$app->params['projectCode']));
                break;
            case 'a002':
                $printFormController = new A002Controller(Yii::$app->params['projectCode'],
                    new PrintModule(Yii::$app->params['projectCode']));
                break;
            default:
                $printFormController = new A000Controller(Yii::$app->params['projectCode'],
                    new PrintModule(Yii::$app->params['projectCode']));
                break;
        }
        $method = 'actionPdfks2';
        $fileTemplate = null;
        if($type == 2) {
            $method = 'actionPdfks2';
            $fileTemplate = Act::FILE_PRINT_FORM_TEMPLATE;
        }
        if($type == 3) {
            $method = 'actionPdfKs3';
            $fileTemplate = ActKs3::FILE_PRINT_FORM_TEMPLATE;
        }

        $fileName = str_replace('{ID}', $id, $fileTemplate);
        $path = Yii::getAlias('@webroot/uploads/protected/KSPrintForms/');
        if (!file_exists($path . $fileName)) {
            $printFormController->$method($id, true);
            echo 'По акту id:' . $id . " не обнаружена печатная форма. ПФ создана принудительно.\n\r";
        }
        $content = file_get_contents($path . $fileName);
        $md5 = md5($content);
        $fileNameHash = str_replace('.', '_'.$md5.'.', $fileName);
        file_put_contents($path . $fileNameHash, $content);
        return $fileName;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/PostTemplate.html
     * @param array $params
     * @return bool|string
     */
    public function postTemplate($params = [])
    {
        $templateToPost = TemplateToPost::fromArray($params);
        $protoData = (string)$templateToPost->toStream();
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/PostTemplate',
            'operationId' => md5($protoData),
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['requestClass'] = Template::class;
        self::$params['postFields'] = $protoData;

        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/PostMessage.html
     * @param array $params
     * @return bool|string
     */
    public function postMessage($params = [])
    {
        $messageToPost = MessageToPost::fromArray($params);
        $protoData = (string)$messageToPost->toStream();
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V3/PostMessage',
            'operationId' => md5($protoData),
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['requestClass'] = Message::class;
        self::$params['postFields'] = $protoData;
        self::$params['curlRequestDump'] = 0;
        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * Метод позволяет помечать документы как удаленные.
     * Если параметр documentId не задан, то сообщение messageId удаляется целиком,
     * и все документы в нем автоматически помечаются как удаленные.
     * Когда из сообщения удаляется последний документ,
     * само сообщение (структуры Message, Template) также помечается как удаленное
     * https://api-docs.diadoc.ru/ru/latest/http/Delete.html
     * Для вызова этого метода текущий пользователь должен иметь доступ ко всем удаляемым документам, в противном случае возвращается код ошибки 403 (Forbidden).
     */
    public function delete($params)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/Delete',
            'boxId' => $params['boxId'],
            'messageId' => $params['messageId'],
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['postFields'] = Json::encode([]);
        self::$params['curlRequestDump'] = 0;

        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * Отправка файла на "полку"
     * https://api-docs.diadoc.ru/ru/latest/http/ShelfUpload.html
     * @param string $nameOnShelf
     * @return false|string
     */
    public function shelfUpload($nameOnShelf) {

        $path = Yii::getAlias('@webroot' . '/uploads/protected/KSPrintForms/');
        if(!file_exists($path . $nameOnShelf)) {
            $this->throwException(\Yii::t('eds', 'Файл не найден'));
        }

        $data = file_get_contents($path . $nameOnShelf);
        if(!$data) {
            $this->throwException(\Yii::t('eds', 'Указанный файл пустой'));
        }

        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/ShelfUpload',
            'nameOnShelf' => '__userId__/' . $nameOnShelf,
            'partIndex' => 0,
            'isLastPart' => 1,
        ]);

        /** @var DiadocSetting $settings */
        $settings = Diadoc::$settings;
        $headers = "Content-type: application/x-www-form-urlencoded\r\nAuthorization: DiadocAuth ddauth_api_client_id=$settings->diadoc_client_id,ddauth_token=$settings->token";
        $options = [
            'http' => [
                'header'  => $headers,
                'method'  => 'POST',
                'content' => $data,
            ],
        ];

        return file_get_contents(self::$params['url'], false, stream_context_create($options));
    }

    /** Получение документа с "полки" */
    public function shelfDownload($nameOnShelf) {

        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/ShelfDownload',
            'nameOnShelf' => '__userId__/' . $nameOnShelf,
        ]);

        self::$params['requestType'] = 'GET';

        self::$params['curlRequestDump'] = 0;

        self::$response = $this->curlRequest();

        return self::$response;
    }

    /**
     * Метод возвращает описание типов документов, доступных в ящике
     * https://api-docs.diadoc.ru/ru/latest/http/GetDocumentTypes.html
     * @return bool|string
     */
    public function getDocumentTypes($boxId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetDocumentTypes',
            'boxId' => $boxId,
        ]);
        self::$params['requestType'] = 'GET';
        self::$params['requestClass'] = DocumentTypesResponseV2::class;
        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/SendDraft.html
     * @param array $params
     * @return bool|string
     */
    public function sendDraft($params = [])
    {
        $draftToSend = DraftToSend::fromArray($params);
        $protoData = (string)$draftToSend->toStream();
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/SendDraft',
            'operationId' => md5($protoData),
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['requestClass'] = DraftToSend::class;
        self::$params['postFields'] = $protoData;
        self::$response = $this->curlRequest();
        return self::$response;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetBox.html
     * @param string $boxId
     * @return Box|\Protobuf\Message
     */
    public function getBox(string $boxId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetBox',
            'boxId' => $boxId,
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        // Возвращает данные другим способом. Вероятно так будет правильнее!!!
        return Box::fromStream(self::$response);
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetOrganization.html
     * @param string $key
     * @param string $value
     * @return bool|string
     */
    public function getOrganization(string $key, string $value)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetOrganization',
            $key => $value,
        ]);
        self::$params['requestType'] = 'GET';
        self::$params['requestClass'] = Organization::class;
        self::$response = $this->curlRequest();
        // Возвращает данные другим способом. Вероятно так будет правильнее!!!
        return self::$response;
    }

    /** Найти организацию по параметру */
    public function findOrganizationByParam($organizations = [], $key, $value)
    {
        if(!$key) {
            die('Не указан ключ');
        }
        if(!$value) {
            die('Не указано значение');
        }
        foreach ($organizations as $item) {
            if($item[$key] == $value) {
                return $item;
            }
        }
        return null;
    }

    private function throwException($error)
    {
        throw new Exception($error);
    }

    private function isError()
    {
        return (self::$error)?true:false;
    }

    private function isResponse()
    {
        return (self::$response)?true:false;
    }


    /** Выполнение cURL */
    private static function curlRequest()
    {
        $curl = curl_init();
        $params = [
            CURLOPT_URL => self::$params['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => self::$params['requestType'],
            CURLOPT_HTTPHEADER => self::$params['httpHeader'],
            // CURLOPT_FAILONERROR => true,
        ];
        if (isset(self::$params['postFields']) && self::$params['postFields']) {
            $params[CURLOPT_POSTFIELDS] = self::$params['postFields'];
        }
        curl_setopt_array($curl, $params);
        $response = curl_exec($curl);
        if($response) {
            $json = json_decode($response);
            $curlInfo = curl_getinfo($curl);
            self::$errorCode = $curlInfo['http_code'];
            if ($curlInfo['http_code'] == 200 && $json->suggestions) {
                self::$response = $json->suggestions[0];
            } elseif ($curlInfo['http_code'] != 200) {
                if (\Yii::$app instanceof Application) {
                    $userId = \Yii::$app->user->id;
                } else {
                    $userId = 0;
                }
                self::$error = $response;
                // Сохранение в логи не корретных ответов сервиса
                $diaDocLog = new ChangeLog();
                $diaDocLog->user_id = $userId;
                $diaDocLog->page = 'Синхронизация данных с сервисом diadoc.ru';
                $diaDocLog->type_id = ChangeLog::TYPE_DIADOC;
                $diaDocLog->action_id = ChangeLog::ACTION_DECLINED;
                $diaDocLog->time = date('Y-m-d H:i:s');
                $diaDocLog->data_before = serialize((array)$json);
                $diaDocLog->comment = $response;
                if (\Yii::$app instanceof Application) {
                    $diaDocLog->save(false);
                }
            }
        }
        if(isset(self::$params['curlRequestDump']) && self::$params['curlRequestDump']) {
            echo $response;
        }
        curl_close($curl);
        unset(self::$params['postFields']);
        if(isset(self::$params['requestClass']) && self::$errorCode == 200) {
            $response = self::$params['requestClass']::fromStream($response);
            unset(self::$params['requestClass']);
        }
        return $response;
    }

    private function getRecipientResponseStatuses()
    {
        return [
            self::RecipientResponseStatus_RecipientResponseStatusUnknown => 'Требуется подписать и отправить',
            self::RecipientResponseStatus_RecipientResponseStatusNotAcceptable => 'Аннулирован',
            self::RecipientResponseStatus_WithRecipientSignature => 'Подписан контрагентом',
            self::RecipientResponseStatus_RecipientSignatureRequestRejected => 'Контрагент отказал в подписи',
        ];
    }

    public function getRecipientResponseStatus($statusId)
    {
        return $this->getRecipientResponseStatuses()[$statusId];
    }

    /**
     * Пока так. Не придумал как очистить Бокс ИД от мусора
     */
    public function clearBoxId($boxId) : string
    {
        return str_replace('@diadoc.ru', '', $boxId);
    }

}