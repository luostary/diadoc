<?php

namespace app\components;

use app\components\keyStorage\KeyStorage;
use app\components\proto\classes\Box;
use app\components\proto\classes\Counteragent;
use app\components\proto\classes\CounteragentList;
use app\components\proto\classes\Document;
use app\components\proto\classes\DocumentList;
use app\components\proto\classes\DraftToSend;
use app\components\proto\classes\Message;
use app\components\proto\classes\MessageToPost;
use app\components\proto\classes\Organization;
use app\components\proto\classes\OrganizationList;
use app\components\proto\classes\TemplateToPost;
use app\models\base\ChangeLog;
use app\models\DiadocSetting;
use yii\base\Component;
use yii\helpers\HtmlPurifier;
use yii\helpers\Json;
use yii\httpclient\Exception;

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
        // $this->authorization();$this->setToken();return;
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
        if(self::$settings) {
            return new self();
        }
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
                //'url' => Url::to([
                '/V3/Authenticate',
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
     * @param $counterAgentInn
     * @return bool
     */
    public function checkRelationshipOrganizations($counterAgentInn)
    {
        $organizationDiaDocId = self::getMyOrganizationsV2()->getOrganizationsList()[0]->getOrgId();
        $partnerDiaDocId = self::getOrganization('inn', $counterAgentInn)->getOrgId();
        $counterAgent = self::getCounteragent($organizationDiaDocId, $partnerDiaDocId);
        return (
            $counterAgent->getCurrentStatus()->name() == 'IsMyCounteragent' &&
            $counterAgent->getCurrentStatus()->value() == 1);
    }

    /**
     * Список контрагентов относящихся к организации
     * Docs http://api-docs.diadoc.ru/ru/latest/http/GetCounteragents.html
     */
    public function getCounterAgents(string $myOrgId = null, string $counteragentStatus = null)
    {
        if ($myOrgId == null) {
            die('Не указан ИД организации');
        }
        $urlParams = [
            '/V2/GetCounteragents',
            'myOrgId' => $myOrgId,
            'outputFormat' => 'xml'
        ];
        if ($counteragentStatus) {
            $urlParams['counteragentStatus'] = $counteragentStatus;
        }
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl($urlParams);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        $parser = new XmlParser();
        return $parser->parse(self::$response, 'Content-Type: application/xml');
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetCounteragents.html?#v3
     * @param string|null $myOrgId
     * @param string|null $counteragentStatus
     * @return CounteragentList|\Protobuf\Message
     */
    public function getCounterAgentsV2(string $myOrgId = null, string $counteragentStatus = null)
    {
        if ($myOrgId == null) {
            die('Не указан ИД организации');
        }
        $urlParams = [
            '/V2/GetCounteragents',
            'myOrgId' => $myOrgId,
        ];
        if ($counteragentStatus) {
            $urlParams['counteragentStatus'] = $counteragentStatus;
        }
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl($urlParams);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        return CounteragentList::fromStream(self::$response);
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
        foreach ($counterAgents['Counteragent'] as $item) {
            if ($item->Organization[$key] == $value) {
                return $item;
            }
        }
        return null;
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetMessage.html
     * @param $boxId
     * @param $messageId
     * @return Message|\Protobuf\Message
     */
    public function getMessage($boxId, $messageId)
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V5/GetMessage',
            'boxId' => $boxId,
            'messageId' => $messageId,
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        if(self::$errorCode != 200){
            return self::$error;
        }
        return Message::fromStream(self::$response);
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetDocument.html
     * @param $boxId
     * @param $messageId
     * @param $entityId
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
        self::$response = $this->curlRequest();
        if(self::$errorCode != 200){
            return self::$error;
        }
        return Document::fromStream(self::$response);
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
            'outputFormat' => 'xml',
            'autoRegister' => 'false'
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        return $this->response();
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
        $items = $this->getMyOrganizationsV2()->getOrganizationsList();
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
     * @param $myOrgId
     * @param $counteragentOrgId
     * @return Counteragent|\Protobuf\Message
     */
    public function getCounteragent($myOrgId, $counteragentOrgId)
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

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/PostTemplate.html
     * @param array $params
     * @return mixed|\SimpleXMLElement
     */
    public function postTemplate($params = [])
    {
        $templateToPost = TemplateToPost::fromArray($params);
        $protoData = (string)$templateToPost->toStream();
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/PostTemplate',
            'operationId' => md5($protoData),
            // 'outputFormat' => 'xml',
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['postFields'] = $protoData;

        self::$response = $this->curlRequest();
        return $this->response();
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/PostMessage.html
     * @param array $params
     * @return \SimpleXMLElement
     */
    public function postMessage($params = [])
    {
        $messageToPost = MessageToPost::fromArray($params);
        $protoData = (string)$messageToPost->toStream();
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/V3/PostMessage',
            'operationId' => md5($protoData),
            // 'outputFormat' => 'xml',
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['postFields'] = $protoData;
        self::$params['curlRequestDump'] = 0;

        self::$response = $this->curlRequest();

        /** @phpstan-ignore-next-line */
        return Message::fromStream(self::$response)->getMessageId();
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
     * @param string $filePath
     * @return false|string
     */
    public function shelfUpload($nameOnShelf = 'trolo_name', $filePath = '/var/www/http/web/uploads/protected/KSPrintForms/KS2PrintForm_394.pdf') {


        $url = self::$baseUrl . "/ShelfUpload?nameOnShelf=__userId__/$nameOnShelf&partIndex=0&isLastPart=1";

        $data = file_get_contents($filePath);

        $devKey = self::$settings->diadoc_client_id;
        $token = self::$settings->token;
        $headers = "Content-type: application/x-www-form-urlencoded\r\nAuthorization: DiadocAuth ddauth_api_client_id=$devKey,ddauth_token=$token";

        $options = [
            'http' => [
                'header'  => $headers,
                'method'  => 'POST',
                'content' => $data,
            ],
        ];

        $context  = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        return $result;
    }

    /**
     * Метод возвращает описание типов документов, доступных в ящике
     * https://api-docs.diadoc.ru/ru/latest/http/GetDocumentTypes.html
     * @return proto\classes\DocumentTypeDescription|\SimpleXMLElement
     */
    public function getDocumentTypes()
    {
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/GetDocumentTypes',
            'boxId' => 'e77319b2f2ff4983b31ea1826a551b75',
            // 'outputFormat' => 'xml',
            #'autoRegister' => 'false'
        ]);
        self::$params['requestType'] = 'GET';
        self::$response = $this->curlRequest();
        // todo доделать вывод данных
        return $documentTypeDescription = \app\components\proto\classes\DocumentTypeDescription::fromStream(self::$response);
        return $this->response();
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/SendDraft.html
     * @param array $params
     * @return DraftToSend|false|\Protobuf\Message
     */
    public function sendDraft($params = [])
    {
        $draftToSend = DraftToSend::fromArray($params);
        $protoData = (string)$draftToSend->toStream();
        self::$params['url'] = self::$externalUrlManager->createAbsoluteUrl([
            '/SendDraft',
            'operationId' => md5($protoData),
            // 'outputFormat' => 'xml',
        ]);
        self::$params['requestType'] = 'POST';
        self::$params['postFields'] = $protoData;

        self::$response = $this->curlRequest();
        if(self::$errorCode != 200) {
            return false;
        }
        return DraftToSend::fromStream(self::$response);
    }

    /**
     * https://api-docs.diadoc.ru/ru/latest/http/GetBox.html
     * @param $boxId
     * @return Box|\Protobuf\Message
     */
    public function getBox($boxId)
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
     * @param $key
     * @param $value
     * @return Organization|\Protobuf\Message
     */
    public function getOrganization($key, $value)
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

    /** Обработка ответа */
    private function response()
    {
        $parser = new XmlParser();
        return new \SimpleXMLElement(self::$response);
        if(is_string(self::$response)) {
            die(self::$response);
        }
        return $parser->parse(self::$response, 'Content-Type: application/xml');
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
                self::$error = $response;
                // Сохранение в логи не корретных ответов сервиса
                $diaDocLog = new ChangeLog();
                $diaDocLog->user_id = \Yii::$app->user->id;
                $diaDocLog->page = 'Синхронизация данных с сервисом diadoc.ru';
                $diaDocLog->type_id = ChangeLog::TYPE_DIADOC;
                $diaDocLog->action_id = ChangeLog::ACTION_DECLINED;
                $diaDocLog->time = date('Y-m-d H:i:s');
                $diaDocLog->data_before = serialize((array)$json);
                $diaDocLog->comment = $response;
                $diaDocLog->save(false);
            }
        }
        if(isset(self::$params['curlRequestDump']) && self::$params['curlRequestDump']) {
            echo $response;
        }
        curl_close($curl);
        unset(self::$params['postFields']);
        if(isset(self::$params['requestClass'])) {
            $response = self::$params['requestClass']::fromStream($response);
            unset(self::$params['requestClass']);
        }
        return $response;
    }

}