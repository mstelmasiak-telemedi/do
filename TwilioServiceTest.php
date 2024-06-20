<?php

declare(strict_types=1);

namespace App\Tests\unit\Service\Twilio;

use App\Api\Serializer\Serializer;
use App\Constant\LanguageConstant;
use App\Constant\Sms\SmsActionConstants;
use App\Core\Components\Allianz\Infrastructure\Api\Cas\Response\ResponseInterface;
use App\Core\Components\Messenger\Domain\Constant\SmsTypeEnum;
use App\Core\Components\Notification\Application\Service\NotificationService;
use App\Core\Components\PasswordlessMagicLink\Infrastructure\Service\PasswordlessMagicLinkGenerator;
use App\DTO\MediaServer\SendSMSResponse;
use App\Entity\Clinic;
use App\Entity\ClinicSettings\V2\ClinicAllianzSettings;
use App\Entity\Consultation;
use App\Entity\User;
use App\Infrastructure\UrlResolver\UrlResolver;
use App\Logger\Logger;
use App\Repository\ConsultationRepository;
use App\Service\ClinicManager;
use App\Service\ClinicTranslationManager;
use App\Service\Consultation\Log\CreateConsultationLogHandler;
use App\Service\ConsultationLogManager;
use App\Service\Email;
use App\Service\ExamCart\ExamCartService;
use App\Service\MagicLink\MedicalDocumentationMagicLinkHandler;
use App\Service\MagicLink\PeselAuthenticationToConsultationLinkService;
use App\Service\MediaServer;
use App\Service\Notification\WhatsApp\WhatsAppNotificationsService;
use App\Service\PhoneNumberManager;
use App\Service\Sms\Actions\Link\LinkGenerator;
use App\Service\Sms\VisitsCancellationService;
use App\Service\SpecializationTranslationService;
use App\Service\Twilio;
use App\Twig\Extension\LanguageExtension;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\SerializerInterface;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\RouterInterface;

class TwilioServiceTest extends TestCase
{
    const SEND_TWILIO_SMS_SUB_METHOD = 'sendTwilioSms';
    const SEND_ALLIANZ_SMS_SUB_METHOD = 'sendAllianzSms';
    const SEND_SMS_API_SMS_SUB_METHOD = 'sendSmsApiSms';
    const SEND_SMS_SUB_METHODS = [
        self::SEND_TWILIO_SMS_SUB_METHOD,
        self::SEND_ALLIANZ_SMS_SUB_METHOD,
        self::SEND_SMS_API_SMS_SUB_METHOD
    ];
    const SEND_MEDIA_SERVER_SMS_METHOD = 'sendSms';
    private $validPhoneNumberStrings = [
        'Without special formatting' => '500625383',
        'With country code' => '+48500625383',
        'With dashes' => '500-625-383',
        'With dashes and country code' => '+48-500-625-383',
        'With spaces' => '500 625 383',
        'With spaces and country code' => '+48 500 625 383',
    ];
    // Dynamically in
    private $validPhoneNumberObjects = [];
    private $validUserRecipients = [];

    private $validBodyTexts = [
        'Simple text' => 'Lorem ipsum',
        'Complicated text' => 'Combining 🌟 Emoji, numbers 12345 and   white  space and      tabs',
        'Special characters' => '!@#$%^&*()_+-=[]{}|;:\'",.<>/?',
        'Escaped characters' => "Line 1\nLine 2\rLine 3\tTabbed",
        'Very long string' => "This is a very long string. This is a very long string.This is a very long string.This is a very long string.This is a very long string. This is a very long string. This is a very long string.This is a very long string.  This is a very long string.v This is a very long string. This is a very long string.v This is a very long string. v  v v This is a very long string.",
        'Cyrillic text' => 'Привет как дела? Это текст на кириллице.',
        'Hindi text' => 'नमस्ते यह हिंदी में एक पाठ है',
        'Chinese text' => '你好，这是中文文本',
        'Japanese text' => 'こんにちは、これは日本語のテキストです',
        'Korean text' => '안녕하세요, 이것은 한국어 텍스트입니다',
        'Arabic text' => 'مرحبًا ، هذا نص باللغة العربية',
        'Hebrew text' => 'שלום, זהו טקסט בעברית',
        'Emoji mix' => '😀 😃 😄 😁 😆 😅 😂 🤣 ☺️ 😊 😇 🙃 😉 😌 😍 🥰 😘 😗 😙 😚 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🤩 🥳 🤗 🤔 🤭 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥺 😳 🤯 😪 😵 🤤 😴 🤒 🤕 🤢 🤮 🤧 😷 🤠 🤡 🤑 🤗 💩 👻 💀 ☠️ 👽 🤖 🎃',
        'Right-to-left mix' => 'This is English مرحبًا هذا نص بالعربية and back to English',
        'Math symbols' => '∑ ∫ µ Π π Ω ω β α √ ∛ ∜',
        'Quotation marks' => '“Good morning,” said John. ‘How are you?’ “I’m fine, thanks!” he replied.',
    ];

    /**
     * @var Twilio
     */
    private $twilio;

    /**
     * @var EntityManagerInterface|MockObject
     */
    private $entityManagerMock;

    /**
     * @var PhoneNumberManager|MockObject
     */
    private $phoneNumberManagerMock;

    /**
     * @var RouterInterface|MockObject
     */
    private $routerMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerSMSMock;

    /**
     * @var ClinicTranslationManager|MockObject
     */
    private $clinicTranslationManagerMock;

    /**
     * @var ConsultationLogManager|MockObject
     */
    private $consultationLogManagerMock;

    /**
     * @var Email|MockObject
     */
    private $emailMock;

    /**
     * @var ClinicManager|MockObject
     */
    private $clinicManagerMock;

    /**
     * @var ParameterBagInterface|MockObject
     */
    private $parameterBagMock;

    /**
     * @var KernelInterface|MockObject
     */
    private $kernelMock;

    /**
     * @var LanguageExtension|MockObject
     */
    private $languageExtensionMock;

    /**
     * @var MediaServer|MockObject
     */
    private $mediaServerMock;

    /**
     * @var PeselAuthenticationToConsultationLinkService|MockObject
     */
    private $peselAuthenticationToConsultationLinkMock;

    /**
     * @var ExamCartService|MockObject
     */
    private $examCartServiceMock;

    /**
     * @var LinkGenerator|MockObject
     */
    private $smsActionLinkGeneratorMock;

    /**
     * @var NotificationService|MockObject
     */
    private $notificationServiceMock;

    /**
     * @var MedicalDocumentationMagicLinkHandler|MockObject
     */
    private $medicalDocumentationMagicLinkHandlerMock;

    /**
     * @var UrlResolver|MockObject
     */
    private $urlResolverMock;

    /**
     * @var WhatsAppNotificationsService|MockObject
     */
    private $whatsAppNotificationsServiceMock;

    /**
     * @var PasswordlessMagicLinkGenerator|MockObject
     */
    private $passwordlessMagicLinkGeneratorMock;

    /**
     * @var MessageBusInterface|MockObject
     */
    private $messageBusMock;

    /**
     * @var SpecializationTranslationService|MockObject
     */
    private $specializationTranslationServiceMock;

    /**
     * @var VisitsCancellationService|MockObject
     */
    private $visitsCancellationServiceMock;

    /**
     * @var User|MockObject
     */
    private $userThatCanBeSentSmsMock;

    /**
     * @var Clinic|MockObject
     */
    private $clinicMock;

    /**
     * @var CreateConsultationLogHandler|MockObject
     */
    private $createConsultationLogHandler;

    /**
     * @var ConsultationRepository|MockObject
     */
    private $consultationRepository;

    /**
     * @var SerializerInterface|MockObject
     */
    private $serializerMock;



    /**
     *
     * This approach might seem unconventional at first glance because the `sendSms` method is
     * designed to return `$this` (the service instance) regardless of the outcome, making it
     * challenging to directly assess the method's internal behavior or the execution path taken.
     * To address this, we count the number of times and identify which of the sendSMS sub-methods
     * are actually invoked during execution. This is particularly relevant given the method's
     * flexibility in handling different types of inputs and the necessity to adhere to specific
     * behavior (e.g., choosing the correct SMS sending mechanism based on parameters).
     *
     * The constants `SEND_SMS_SUB_METHODS` define the sub-methods (`sendTwilioSms`, `sendAllianzSms`,
     * `sendSmsApiSms`) that are considered in this test, reflecting the different SMS sending strategies
     * supported by the service.
     */
    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->phoneNumberManagerMock = new PhoneNumberManager();
        $this->routerMock = $this->createMock(RouterInterface::class);
        $this->loggerSMSMock = $this->createMock(Logger::class);
        $this->clinicTranslationManagerMock = $this->createMock(ClinicTranslationManager::class);
        $this->consultationLogManagerMock = $this->createMock(ConsultationLogManager::class);
        $this->emailMock = $this->createMock(Email::class);
        $this->clinicManagerMock = $this->createMock(ClinicManager::class);
        $this->clinicMock = $this->createMock(Clinic::class);
        $this->clinicManagerMock->method('getCurrentClinic')->willReturn($this->clinicMock);
        $this->parameterBagMock = $this->createMock(ParameterBagInterface::class);
        $this->kernelMock = $this->createMock(KernelInterface::class);
        $this->languageExtensionMock = $this->createMock(LanguageExtension::class);
        $this->mediaServerMock = $this->createMock(MediaServer::class);
        $this->peselAuthenticationToConsultationLinkMock = $this->createMock(PeselAuthenticationToConsultationLinkService::class);
        $this->examCartServiceMock = $this->createMock(ExamCartService::class);
        $this->smsActionLinkGeneratorMock = $this->createMock(LinkGenerator::class);
        $this->notificationServiceMock = $this->createMock(NotificationService::class);
        $this->medicalDocumentationMagicLinkHandlerMock = $this->createMock(MedicalDocumentationMagicLinkHandler::class);
        $this->urlResolverMock = $this->createMock(UrlResolver::class);
        $this->whatsAppNotificationsServiceMock = $this->createMock(WhatsAppNotificationsService::class);
        $this->passwordlessMagicLinkGeneratorMock = $this->createMock(PasswordlessMagicLinkGenerator::class);
        $this->messageBusMock = $this->createMock(MessageBusInterface::class);
        $this->specializationTranslationServiceMock = $this->createMock(SpecializationTranslationService::class);
        $this->visitsCancellationServiceMock = $this->createMock(VisitsCancellationService::class);
        $this->createConsultationLogHandler = $this->createMock(CreateConsultationLogHandler::class);
        $this->consultationRepository = $this->createMock(ConsultationRepository::class);
        $this->userThatCanBeSentSmsMock = $this->getUserThatCanBeSentSmsMock();
        $this->serializerMock = $this->createMock(SerializerInterface::class);
        $this->twilio = new Twilio(
            'sid',
            'token',
            'smsNumber',
            'smsCancelConsultationNumber',
            'smsApiToken',
            'vpozSignatureAppHost',
            'smsapi2wayNumber',
            false,
            'smsNumberUs',
            $this->entityManagerMock,
            $this->phoneNumberManagerMock,
            $this->routerMock,
            $this->loggerSMSMock,
            $this->clinicTranslationManagerMock,
            $this->consultationLogManagerMock,
            $this->emailMock,
            $this->clinicManagerMock,
            $this->parameterBagMock,
            $this->kernelMock,
            $this->languageExtensionMock,
            $this->mediaServerMock,
            $this->peselAuthenticationToConsultationLinkMock,
            $this->examCartServiceMock,
            $this->smsActionLinkGeneratorMock,
            $this->notificationServiceMock,
            $this->medicalDocumentationMagicLinkHandlerMock,
            $this->urlResolverMock,
            $this->whatsAppNotificationsServiceMock,
            $this->passwordlessMagicLinkGeneratorMock,
            $this->messageBusMock,
            $this->specializationTranslationServiceMock,
            $this->visitsCancellationServiceMock,
            $this->createConsultationLogHandler,
            $this->consultationRepository
        );

    }

    private function sendSmsValidDataProvider(): \Generator
    {
        // FIRST VALUE IN VALUES ARRAY FOR EACH KEY IS THE DEFAULT
        $someConsultation = (new Consultation())->setDelayed(true)->setStatus(Consultation::STATUS_VERIFICATION_CORRECT);
        $validParametersAndValues = [
            'from' => [SmsActionConstants::SMS_SENDER],
            'useTwilio' => [false, true],
            'isSmsWithAnswer' => [false, true],
            'lang' => [LanguageConstant::PL, LanguageConstant::DEFAULT, LanguageConstant::EN, LanguageConstant::RU],
            'checkSendSms' => [true, false],
            'type' => [SmsTypeEnum::OTHER, SmsTypeEnum::PING_DOCTOR_BY_SMS, SmsTypeEnum::SEND_CABIN_SMS],
            'consultation' => [null, $someConsultation],
        ];
        $defaultParams = [];
        foreach ($validParametersAndValues as $key => $values) {
            $defaultParams[$key] = $values[0];
        }

        $this->populateValidPhoneNumberObjects();
        $this->populateValidUserRecipients();
        $validRecipients = array_merge(
            $this->validPhoneNumberStrings,
            $this->validPhoneNumberObjects,
            $this->validUserRecipients);

        $validSimpleRecipient = $validRecipients['Without special formatting'];
        $validSimpleBody = $this->validBodyTexts['Simple text'];
        yield 'Test on default values' => [$validSimpleRecipient, $validSimpleBody, ...$defaultParams];

        //1. Test valid body inputs
        foreach ($this->validBodyTexts as $description => $body) {
            yield $description => [
                'recipient' => $validSimpleRecipient,
                'body' => $body,
                ...$defaultParams,
            ];
        }

        //2. Test valid recipients
        foreach ($validRecipients as $description => $recipient) {
            yield $description => [
                'recipient' => $recipient,
                'body' => $validSimpleBody,
                ...$defaultParams,
            ];
        }

        // 3. Test other parameters
        $counter = 1; //counter is added to make each test name unique
        foreach ($validParametersAndValues as $parameterName => $possibleValues) {
            foreach ($possibleValues as $parameterValue) {
                $testCaseParams = array_merge($defaultParams, [$parameterName => $parameterValue]);
                yield "[$counter] Test $parameterName when " . $parameterValue => [
                    'recipient' => $validSimpleRecipient,
                    'body' => $validSimpleBody,
                    ...$testCaseParams
                ];
                $counter++;
            }
        }
    }

    /**
     * @dataProvider sendSmsValidDataProvider
     */
    public function testOnlyOneSendSmsSubMethodIsCalled(
        $recipient,
        $smsBody,
        $from,
        $useTwilio,
        $isSmsWithAnswer,
        $lang,
        $checkSendSms,
        $type,
        $consultation
    )
    {

        $this->clinicMock
            ->method('isSendSms')
            ->willReturn(true);
        $this->clinicManagerMock
            ->method('getCurrentClinic')
            ->willReturn($this->clinicMock);

        $sendSmsSubMethods = self::SEND_SMS_SUB_METHODS;
        $twilioPartialMock = $this->createTwilioPartialMock($sendSmsSubMethods);

        foreach ($sendSmsSubMethods as $methodName) {
            $twilioPartialMock->expects($this->any())
                ->method($methodName)
                ->willReturnCallback(function () use (&$sendSubMethodCallCount) {
                    $sendSubMethodCallCount++;
                });
        }
        // last send sms method is on a different object
        $this->mediaServerMock->expects($this->any())
            ->method(self::SEND_MEDIA_SERVER_SMS_METHOD)
            ->willReturnCallback(function () use (&$sendSubMethodCallCount) {
                $sendSubMethodCallCount++;
            });

        $twilioPartialMock->sendSms(
            $recipient,
            $smsBody,
            from: $from,
            useTwilio: $useTwilio,
            isSmsWithAnswer: $isSmsWithAnswer,
            lang: $lang,
            checkSendSms: $checkSendSms,
            type: $type,
            consultation: $consultation
        );

        $this->assertEquals(1,
            $sendSubMethodCallCount,
            'Only one send SMS method should have been called.'
        );
    }

    private function conditionsWhenSmsShouldNotBeSentProvider(): \Generator
    {
        $userThatCanBeSentSms = $this->getUserThatCanBeSentSmsMock();

        yield "Clinic's isSendSms flag is set to false" => [$userThatCanBeSentSms, false];

        //todo: uncomment when will no longer fail
        //yield "Sms body is empty" => [$userThatCanBeSentSms, true, ""];

        yield "Recipient is null" => [null];
        yield "Recipient is not a valid phone number string" => ["123"];
        yield "Recipient is not a mobile phone number string" => ["123456789"];

        $userWithoutPhoneNumber = $this->createMock(User::class);
        $userWithoutPhoneNumber->method('getPhoneNumber')->willReturn(null);
        yield "User recipient doesn't have a phone number set" => [$userWithoutPhoneNumber];

        $userWithNonMobilePhoneNumber = $this->createMock(User::class);
        $nonMobilePhoneNumber = PhoneNumberUtil::getInstance()->parse("123456789", "PL");
        $userWithNonMobilePhoneNumber->method('getPhoneNumber')->willReturn($nonMobilePhoneNumber);
        yield "User recipient has non-mobile phone number" => [$userWithNonMobilePhoneNumber];
    }

    /**
     * @dataProvider conditionsWhenSmsShouldNotBeSentProvider
     */
    public function testShouldNotSendSmsIf($recipient, $isSendSms = true, $smsBody = "test123")
    {
        $this->clinicMock
            ->method('isSendSms')
            ->willReturn($isSendSms);
        $this->clinicManagerMock
            ->method('getCurrentClinic')
            ->willReturn($this->clinicMock);
        $twilioPartialMock = $this->createTwilioPartialMock(self::SEND_SMS_SUB_METHODS);

        // Expect it not to call any send sms sub methods
        foreach (self::SEND_SMS_SUB_METHODS as $sendSmsSubMethod) {
            $twilioPartialMock->expects($this->never())
                ->method($sendSmsSubMethod);
        }
        // last send sms method is on a different object
        $this->mediaServerMock->expects($this->never())
            ->method(self::SEND_MEDIA_SERVER_SMS_METHOD);

        $twilioPartialMock->sendSms(
            $recipient,
            $smsBody,
            checkSendSms: true
        );
    }

    public function testWillCallSendTwilioSmsIfSendTwilioSmsFlagIsTrue()
    {
        $smsBody = "Test123";
        $smsFrom = SmsActionConstants::SMS_SENDER;
        $smsType = SmsTypeEnum::OTHER;
        $isSmsWithAnswer = false;

        $twilioPartialMock = $this->createTwilioPartialMock(self::SEND_SMS_SUB_METHODS);

        // Expect it to call sendTwilioSms
        $twilioPartialMock->expects($this->once())
            ->method(self::SEND_TWILIO_SMS_SUB_METHOD)
            ->with(
                $this->equalTo($this->userThatCanBeSentSmsMock),
                $this->equalTo($smsBody),
                $this->equalTo($smsFrom),
                $this->equalTo($isSmsWithAnswer),
                $this->equalTo($smsType)
            );

        // Expect it not to call any other send sms methods
        foreach (self::SEND_SMS_SUB_METHODS as $sendSmsSubMethod) {
            if ($sendSmsSubMethod === self::SEND_TWILIO_SMS_SUB_METHOD) {
                continue;
            }

            $twilioPartialMock->expects($this->never())
                ->method($sendSmsSubMethod);
        }
        // last send sms method is on a different object
        $this->mediaServerMock->expects($this->never())
            ->method(self::SEND_MEDIA_SERVER_SMS_METHOD);


        $twilioPartialMock->sendSms(
            $this->userThatCanBeSentSmsMock,
            $smsBody,
            from: $smsFrom,
            useTwilio: true,
            isSmsWithAnswer: $isSmsWithAnswer,
            checkSendSms: false,
            type: $smsType);
    }

    public function testWillCallSendSmsFromMediaServerIfIsSendSmsFromMediaServerFlagIsTrue()
    {
        $smsBody = "test sms";
        $smsFrom = SmsActionConstants::SMS_SENDER;
        $smsType = SmsTypeEnum::OTHER;

        $this->clinicMock->method('isSendSmsFromMediaServer')->willReturn(true);
        $this->clinicManagerMock->method('getCurrentClinic')->willReturn($this->clinicMock);
        $this->mediaServerMock->method('sendSms')->willReturn(true);
        $twilioPartialMock = $this->createTwilioPartialMock(self::SEND_SMS_SUB_METHODS);

        // Expect it to call send Sms on media server
        $this->mediaServerMock->expects($this->once())
            ->method('sendSms')
            ->with(
                $this->equalTo($this->userThatCanBeSentSmsMock),
                $this->equalTo($smsBody),
                $this->equalTo($smsType)
            );
        // Expect it not to call any other send sms methods
        foreach (self::SEND_SMS_SUB_METHODS as $sendSmsSubMethod) {
            $twilioPartialMock->expects($this->never())
                ->method($sendSmsSubMethod);
        }

        $twilioPartialMock->sendSms($this->userThatCanBeSentSmsMock,
            $smsBody,
            from: $smsFrom,
            useTwilio: false,
            checkSendSms: false,
            type: $smsType);
    }

    public function testShouldFallbackToSmsApiIfMediaServerSendingFails()
    {
        $smsBody = "test123123";
        $smsFrom = SmsActionConstants::SMS_SENDER;
        $smsType = SmsTypeEnum::OTHER;

        $this->clinicMock->method('isSendSmsFromMediaServer')->willReturn(true);
        $this->clinicManagerMock->method('getCurrentClinic')->willReturn($this->clinicMock);
        $this->mediaServerMock->method('sendSms')->willReturn(false);
        $userMock = $this->userThatCanBeSentSmsMock;
        $userMock->setPhoneNumber();

        $twilioPartialMock = $this->createTwilioPartialMock([...self::SEND_SMS_SUB_METHODS, 'isForeignNumber']);
        $twilioPartialMock
            ->method('isForeignNumber')
            ->willReturn(false);

        // Expect it to call Sms on media server
        $this->mediaServerMock->expects($this->once())
            ->method(self::SEND_MEDIA_SERVER_SMS_METHOD)
            ->with(
                $this->equalTo($this->userThatCanBeSentSmsMock),
                $this->equalTo($smsBody),
                $this->equalTo($smsType)
            );
        // Expect it to also call sendSmsApiSms as fallback
        $twilioPartialMock->expects($this->once())
            ->method(self::SEND_SMS_API_SMS_SUB_METHOD);

        // Expect it not to call any other send sms methods
        foreach (self::SEND_SMS_SUB_METHODS as $sendSmsSubMethod) {
            if ($sendSmsSubMethod === self::SEND_SMS_API_SMS_SUB_METHOD) {
                continue;
            }

            $twilioPartialMock->expects($this->never())
                ->method($sendSmsSubMethod);
        }

        $twilioPartialMock->sendSms($this->userThatCanBeSentSmsMock,
            $smsBody,
            from: $smsFrom,
            useTwilio: false,
            checkSendSms: false,
            type: $smsType);
    }

    public function testWillNotSendSmsFromMediaServerIfItsAForeignNumber()
    {
        $smsBody = "test123123";
        $smsFrom = SmsActionConstants::SMS_SENDER;
        $smsType = SmsTypeEnum::OTHER;

        $this->clinicMock->method('isSendSmsFromMediaServer')->willReturn(true);
        $this->clinicManagerMock->method('getCurrentClinic')->willReturn($this->clinicMock);
        $this->mediaServerMock->method('sendSms')->willReturn(true);
        $userMock = $this->userThatCanBeSentSmsMock;
        $foreignPhoneNumber = '+66-2-2134567';
        $foreignPhoneNumberObject = PhoneNumberUtil::getInstance()->parse($foreignPhoneNumber, "PL");
        $userMock->setPhoneNumber($foreignPhoneNumberObject);

        $twilioPartialMock = $this->createTwilioPartialMock([...self::SEND_SMS_SUB_METHODS, 'isForeignNumber']);
        $twilioPartialMock
            ->method('isForeignNumber')
            ->willReturn(true);

        // Expect it to not call send Sms on media server
        $this->mediaServerMock->expects($this->never())
            ->method(self::SEND_MEDIA_SERVER_SMS_METHOD);

        $twilioPartialMock->sendSms($this->userThatCanBeSentSmsMock,
            $smsBody,
            from: $smsFrom,
            useTwilio: false,
            checkSendSms: false,
            type: $smsType);
    }

    private function sendTwilioSmsSubMethodValidDataProvider(): \Generator
    {
        // FIRST VALUE IN VALUES ARRAY FOR EACH KEY IS THE DEFAULT
        $validParametersAndValues = [
            'from' => [SmsActionConstants::SMS_SENDER],
            'isSmsWithAnswer' => [false, true],
            'type' => [SmsTypeEnum::OTHER, SmsTypeEnum::PING_DOCTOR_BY_SMS, SmsTypeEnum::SEND_CABIN_SMS],
            'mockClinicName' => ["Test123","#4!$$%\nTest7"]
        ];
        $defaultParams = [];
        foreach ($validParametersAndValues as $key => $values) {
            $defaultParams[$key] = $values[0];
        }

        $this->populateValidPhoneNumberObjects();
        $this->populateValidUserRecipients();
        $validRecipients = array_merge(
            $this->validPhoneNumberStrings,
            $this->validPhoneNumberObjects,
            $this->validUserRecipients);

        $validSimpleRecipient = $validRecipients['Without special formatting'];
        $validSimpleBody = $this->validBodyTexts['Simple text'];
        yield 'Test on default values' => [$validSimpleRecipient,
            $validSimpleBody,
            $validSimpleRecipient
            ,...$defaultParams]
        ;

        //1. Test valid body inputs
        foreach ($this->validBodyTexts as $description => $body) {
            yield $description => [
                'recipient' => $validSimpleRecipient,
                'body' => $body,
                'to'=> $validSimpleRecipient,
                ...$defaultParams,
            ];
        }

        //2. Test valid recipients
        foreach ($validRecipients as $description => $recipient) {
            $to = $recipient;

            yield $description => [
                'recipient' => $recipient,
                'body' => $validSimpleBody,
                'to'=> $to,
                ...$defaultParams,
            ];
        }

        // 3. Test other parameters
        $counter = 1; //counter is added to make each test name unique
        foreach ($validParametersAndValues as $parameterName => $possibleValues) {
            foreach ($possibleValues as $parameterValue) {
                $testCaseParams = array_merge($defaultParams, [$parameterName => $parameterValue]);
                yield "[$counter] Test $parameterName when $parameterValue" => [
                    'recipient' => $validSimpleRecipient,
                    'body' => $validSimpleBody,
                    'to'=> $validSimpleRecipient,
                    ...$testCaseParams
                ];
                $counter++;
            }
        }
    }

    /**
     * @dataProvider sendTwilioSmsSubMethodValidDataProvider
     */
    public function testSendTwilioSmsSubMethod(
        $recipient,
        $body,
        $to,
        $from = SmsActionConstants::SMS_SENDER,
        $isSmsWithAnswer = false,
        $type = SmsTypeEnum::OTHER,
        $mockClinicName = "Test123"
    )
    {
        $number = $recipient;

        $this->clinicMock->method('getName')->willReturn($mockClinicName);
        $this->clinicMock->method('getIsLocalTwilioSmsEnabled')->willReturn(false);

        if ($number instanceof PhoneNumber){
            $number = PhoneNumberManager::getNumberInE164Format($number);
        }

        $isValidClinic = false;
        if ($recipient instanceof User){
            $isValidClinic = true;
            $recipient->setClinic($this->clinicMock);
            $number = $recipient->getReadablePhoneNumber();
        }
        $twilioPartialMock = $this->createTwilioPartialMock([]);

        // Set expectations for the `create` method to be called with specific parameters
        $messagesMock = $this->createMock(\Twilio\Rest\Api\V2010\Account\MessageList::class);
        $expectedBody =  trim($body);
        $expectedBody = $isValidClinic
            ? $mockClinicName . ": ".$expectedBody
            : $expectedBody;
        $messagesMock->expects($this->once())
            ->method('create')
            ->with(
                $this->equalTo($number),
                $this->equalTo([
                    'from' => $from,
                    'body' => $expectedBody
                ])
            );
        $twilioPartialMock->messages = $messagesMock;


        // Expect it not to log any expections
        $this->loggerSMSMock->expects($this->any())
            ->method('error')
            ->with(
                $this->anything(), // First argument can be anything
                $this->callback(function ($context) {
                    return !isset($context['exception']);
                })
            );

        $twilioPartialMock->sendTwilioSms(
            $recipient,
            $body,
            $from,
            $isSmsWithAnswer,
            $type
        );
    }


    public function testSendTwilioSmsSubMethodWillSendFromLocalTwilioNumberIfEnabled(){
        $recipient = $this->getUserThatCanBeSentSmsMock();

        $body = $this->validBodyTexts['Simple text'];
        $mockClinicName = "Mock clinic123";
        $localTwilioSmsNumber = "123456789";

        $recipient->method('getClinic')->willReturn($this->clinicMock);
        $this->clinicMock->method('getName')->willReturn($mockClinicName);
        $this->clinicMock->method('getIsLocalTwilioSmsEnabled')->willReturn(true);
        $this->clinicMock->method('getLocalTwilioSmsNumber')->willReturn($localTwilioSmsNumber);
        $recipient->method('getClinic')->willReturn($this->clinicMock);
        $from =  $this->clinicMock->getLocalTwilioSmsNumber();
        $to = $recipient->getReadablePhoneNumber();
        $twilioPartialMock = $this->createTwilioPartialMock([]);

        // Set expectations for the `create` method to be called with specific parameters
        $messagesMock = $this->createMock(\Twilio\Rest\Api\V2010\Account\MessageList::class);
        $expectedBody = $mockClinicName.": ". trim($body);
        $messagesMock->expects($this->once())
            ->method('create')
            ->with(
                $this->equalTo($to),
                $this->equalTo([
                    'from' => $from,
                    'body' => $expectedBody
                ])
            );
        $twilioPartialMock->messages = $messagesMock;

        // Expect it not to log any expections
        $this->loggerSMSMock->expects($this->any())
            ->method('error')
            ->with(
                $this->anything(), // First argument can be anything
                $this->callback(function ($context) {
                    return !isset($context['exception']);
                })
            );

        $twilioPartialMock->sendTwilioSms(
            $recipient,
            $body,
            $from
        );
    }

    //Todo: Implement when the method is made testable
//    public function testSendSmsApiSubMethod(
//        $recipient,
//        $body,
//        $from = SmsActionConstants::SMS_SENDER,
//        $isSmsWithAnswer = false,
//        $forceSmsApi = false,
//        string $type = SmsTypeEnum::OTHER)
//    {
//
//    }
    private function sendMediaServerSmsDataProvider(): \Generator
    {
        // FIRST VALUE IN VALUES ARRAY FOR EACH KEY IS THE DEFAULT
        $validParametersAndValues = [
            'type' => [SmsTypeEnum::OTHER, SmsTypeEnum::PING_DOCTOR_BY_SMS, SmsTypeEnum::SEND_CABIN_SMS],
        ];
        $defaultParams = [];
        foreach ($validParametersAndValues as $key => $values) {
            $defaultParams[$key] = $values[0];
        }

        $this->populateValidPhoneNumberObjects();
        $this->populateValidUserRecipients();
        $validRecipients = array_merge(
            $this->validPhoneNumberStrings,
            $this->validPhoneNumberObjects,
            $this->validUserRecipients);

        $validSimpleRecipient = $validRecipients['Without special formatting'];
        $validSimpleBody = $this->validBodyTexts['Simple text'];
        yield 'Test on default values' => [$validSimpleRecipient,
            $validSimpleBody,
            $validSimpleRecipient
            ,...$defaultParams]
        ;

        //1. Test valid body inputs
        foreach ($this->validBodyTexts as $description => $body) {
            yield $description => [
                'recipient' => $validSimpleRecipient,
                'body' => $body,
                ...$defaultParams,
            ];
        }

        //2. Test valid recipients
        foreach ($validRecipients as $description => $recipient) {
            $to = $recipient;

            yield $description => [
                'recipient' => $recipient,
                'body' => $validSimpleBody,
                ...$defaultParams,
            ];
        }

        // 3. Test other parameters
        $counter = 1; //counter is added to make each test name unique
        foreach ($validParametersAndValues as $parameterName => $possibleValues) {
            foreach ($possibleValues as $parameterValue) {
                $testCaseParams = array_merge($defaultParams, [$parameterName => $parameterValue]);
                yield "[$counter] Test $parameterName when $parameterValue" => [
                    'recipient' => $validSimpleRecipient,
                    'body' => $validSimpleBody,
                    ...$testCaseParams
                ];
                $counter++;
            }
        }
    }

    /**
     * @dataProvider sendMediaServerSmsDataProvider
     */
    public function testMediaServerSendSmsMethod(
        $recipient,
        string $body,
        string $type = SmsTypeEnum::OTHER
    ){
        // Mock successful http response
        $serializer = SerializerBuilder::create()->build();
        $apiResponseContent = '{
          "status": "someStatus",
          "line": "someLine",
          "message": "someMessage"
          }';
        $responseSuccessful = $serializer->deserialize($apiResponseContent, SendSMSResponse::class, 'json');

        $httpClientMock = $this->createMock(\GuzzleHttp\Client::class);
        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $bodyStreamMock = $this->createMock(StreamInterface::class);
        $bodyStreamMock->method('getContents')
            ->willReturn($body);
        $responseMock->method('getBody')
            ->willReturn($bodyStreamMock);
        $httpClientMock->method('__call')
            ->willReturnCallback(function () use (&$numberOfTries) {
                $numberOfTries++;
            })
            ->willReturn($responseMock);
        $this->serializerMock->method('deserialize')
            ->willReturn($responseSuccessful);

        // Expect it to not log any errors
        $this->loggerSMSMock->expects($this->any())
            ->method('error')
            ->with(
                $this->anything(), // First argument can be anything
                $this->callback(function ($context) {
                    return !isset($context['exception']);
                })
            );

        $mediaServerMock = $this->createMediaServerPartialMock([]);
        $mediaServerMock->setClient($httpClientMock);

        //Returns true on success
        $result = $mediaServerMock->sendSms(
            $recipient,
            $body,
            $type
        );
        $this->assertTrue($result);
    }


    public function testMediaServerWillRetryOnError(){
        $body = $this->validBodyTexts['Simple text'];
        $recipient = $this->getUserThatCanBeSentSmsMock();
        $type = SmsTypeEnum::OTHER;

        // Mock successful http response
        $serializer = SerializerBuilder::create()->build();
        $apiResponseContent = '{
          "status": "someStatus",
          "line": "someLine",
          "message": "ERROR ERROR ERROR"
          }';
        $responseSuccessful = $serializer->deserialize($apiResponseContent, SendSMSResponse::class, 'json');

        $httpClientMock = $this->createMock(\GuzzleHttp\Client::class);
        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $bodyStreamMock = $this->createMock(StreamInterface::class);
        $bodyStreamMock->method('getContents')
            ->willReturn($body);
        $responseMock->method('getBody')
            ->willReturn($bodyStreamMock);
        $httpClientMock->method('__call')
            ->willReturnCallback(function () use (&$numberOfTries,$responseMock) {
                $numberOfTries++;
                return $responseMock;
            });

        $this->serializerMock->method('deserialize')
            ->willReturn($responseSuccessful);

        $mediaServerMock = $this->createMediaServerPartialMock([]);
        $mediaServerMock->setClient($httpClientMock);

        //Returns true on success
        $result = $mediaServerMock->sendSms(
            $recipient,
            $body,
            $type
        );

        $this->assertFalse($result);
        $this->assertEquals(MediaServer::MAX_RETRY_NUMBER,$numberOfTries);
    }

    private function createMediaServerPartialMock($mockMethods){

       return $this->getMockBuilder(MediaServer::class)
            ->setConstructorArgs([
                $this->entityManagerMock,
                $this->phoneNumberManagerMock,
                $this->clinicManagerMock,
                $this->languageExtensionMock,
                $this->routerMock,
                $this->serializerMock,
                $this->loggerSMSMock,
                "tokenPlaceholder"
            ])
            ->onlyMethods($mockMethods)
            ->getMock();
    }

    private function phoneNumberProvider(): \Generator
    {
        // INVALID TYPES
        yield 'It can\'t take null as input' => [null, false];
        yield 'It can\'t take integer as input' => [12345, false];
        yield 'It can\'t take boolean as input' => [false, false];

        // STRING INPUTS
        $validPhoneNumberWithoutSpecialFormating = '500625383';
        $validPhoneNumberWithCountryCode = '+48500625383';
        $validPhoneNumberWithDashes = '500-625-383';
        $validPhoneNumberWithDashesAndCountryCode = '+48-500-625-383';
        $validPhoneNumberWithSpaces = '500 625 383';
        $validPhoneNumberWithSpacesAndCountryCode = '+48 500 625 383';
        //invalid
        $invalidPhoneNumberNotNumeric = 'not a number';
        $invalidPhoneNumberTooShort = '12345';
        $invalidPhoneNumberTooLong = '+123456789012345';
        $invalidPhoneNumberWithLetters = '123ABC7890';
        $invalidPhoneNumberNotMobile = "123456789";

        // Valid string inputs
        yield 'It returns true for valid mobile number string without special formatting' => [$validPhoneNumberWithoutSpecialFormating, true];
        yield 'It returns true for valid mobile number with country code' => [$validPhoneNumberWithCountryCode, true];
        yield 'It returns true for valid mobile number with dashes' => [$validPhoneNumberWithDashes, true];
        yield 'It returns true for valid mobile number with dashes and country code' => [$validPhoneNumberWithDashesAndCountryCode, true];
        yield 'It returns true for valid mobile number with spaces' => [$validPhoneNumberWithSpaces, true];
        yield 'It returns true for valid mobile number with spaces and country code' => [$validPhoneNumberWithSpacesAndCountryCode, true];

        // Invalid string inputs
        yield 'It returns false for not numeric phone number' => [$invalidPhoneNumberNotNumeric, false];
        yield 'It returns false for too short phone number' => [$invalidPhoneNumberTooShort, false];
        yield 'It returns false for too long phone number' => [$invalidPhoneNumberTooLong, false];
        yield 'It returns false for phone number with letters' => [$invalidPhoneNumberWithLetters, false];
        yield 'It returns false for not mobile phone number' => [$invalidPhoneNumberNotMobile, false];

        // PHONENUMBER OBJECT INPUTS
        $phoneNumberObjectWithoutSpecialFormatting = PhoneNumberUtil::getInstance()->parse($validPhoneNumberWithoutSpecialFormating, "PL");
        $phoneNumberObjectWithCountryCode = PhoneNumberUtil::getInstance()->parse($validPhoneNumberWithCountryCode, "PL");
        $phoneNumberObjectWithDashes = PhoneNumberUtil::getInstance()->parse($validPhoneNumberWithDashes, "PL");
        $phoneNumberObjectWithDashesAndCountryCode = PhoneNumberUtil::getInstance()->parse($validPhoneNumberWithDashesAndCountryCode, "PL");
        $phoneNumberObjectWithSpaces = PhoneNumberUtil::getInstance()->parse($validPhoneNumberWithSpaces, "PL");
        $phoneNumberObjectWithSpacesAndCountryCode = PhoneNumberUtil::getInstance()->parse($validPhoneNumberWithSpacesAndCountryCode, "PL");
        // invalid
        $phoneNumberObectNotMobile = PhoneNumberUtil::getInstance()->parse($invalidPhoneNumberNotMobile, "PL");

        yield 'It returns true for PhoneNumber object without special formatting' => [$phoneNumberObjectWithoutSpecialFormatting, true];
        yield 'It returns true for PhoneNumber object with country code' => [$phoneNumberObjectWithCountryCode, true];
        yield 'It returns true for PhoneNumber object with dashes' => [$phoneNumberObjectWithDashes, true];
        yield 'It returns true for PhoneNumber object with dashes and country code' => [$phoneNumberObjectWithDashesAndCountryCode, true];
        yield 'It returns true for PhoneNumber object with spaces' => [$phoneNumberObjectWithSpaces, true];
        yield 'It returns true for PhoneNumber object with spaces and country code' => [$phoneNumberObjectWithSpacesAndCountryCode, true];

        yield 'It returns false for not mobile PhoneNumber object' => [$phoneNumberObectNotMobile, false];

        // USER INPUTS
        $userWithPhoneNumberWithoutSpecialFormating = (new User())->setPhoneNumber($phoneNumberObjectWithoutSpecialFormatting);
        $userWithPhoneNumberWithCountryCode = (new User())->setPhoneNumber($phoneNumberObjectWithCountryCode);
        $userWithPhoneNumberWithDashes = (new User())->setPhoneNumber($phoneNumberObjectWithDashes);
        $userWithPhoneNumberWithDashesAndCountryCode = (new User())->setPhoneNumber($phoneNumberObjectWithDashesAndCountryCode);
        $userWithPhoneNumberWithSpaces = (new User())->setPhoneNumber($phoneNumberObjectWithSpaces);
        $userWithPhoneNumberWithSpacesAndCountryCode = (new User())->setPhoneNumber($phoneNumberObjectWithSpacesAndCountryCode);
        // invalid
        $userWithoutPhoneNumber = new User();
        $userWithNotMobilePhoneNumber = (new User())->setPhoneNumber($phoneNumberObectNotMobile);

        yield 'It returns true for user with valid phone number without special formatting' => [$userWithPhoneNumberWithoutSpecialFormating, true];
        yield 'It returns true for user with valid phone number with country code' => [$userWithPhoneNumberWithCountryCode, true];
        yield 'It returns true for user with valid phone number with dashes' => [$userWithPhoneNumberWithDashes, true];
        yield 'It returns true for user with valid phone number with dashes and country code' => [$userWithPhoneNumberWithDashesAndCountryCode, true];
        yield 'It returns true for user with valid phone number with spaces' => [$userWithPhoneNumberWithSpaces, true];
        yield 'It returns true for user with valid phone number with spaces and country code' => [$userWithPhoneNumberWithSpacesAndCountryCode, true];

        yield 'It returns false for user without phone number' => [$userWithoutPhoneNumber, false];
        yield 'It returns false for user with not mobile phone number' => [$userWithNotMobilePhoneNumber, false];
    }

    /**
     * @dataProvider phoneNumberProvider
     */
    public function testIsMobilePhoneNumber($number, $shouldReturn)
    {
        $result = $this->twilio->isMobilePhoneNumber($number);
        $this->assertEquals($shouldReturn, $result);
    }

    private function populateValidPhoneNumberObjects(): void
    {
        foreach ($this->validPhoneNumberStrings as $description => $phoneNumberString) {
            $prefixedDescription = 'Phone number object ' . $description;
            $this->validPhoneNumberObjects[$prefixedDescription] = PhoneNumberUtil::getInstance()->parse($phoneNumberString, "PL");
        }
    }

    private function populateValidUserRecipients(): void
    {
        foreach ($this->validPhoneNumberObjects as $description => $phoneNumberObject) {
            $user = new User();
            $user->setPhoneNumber($phoneNumberObject);
            $user->setClinic($this->clinicMock);
            $prefixedDescription = 'User recipient ' . $description;
            $this->validUserRecipients[$prefixedDescription] = $user;
        }
    }

    private function createTwilioPartialMock($mockedMethods): Twilio|MockObject
    {
        return $this->getMockBuilder(Twilio::class)
            ->setConstructorArgs([
                'sid',
                'token',
                'smsNumber',
                'smsCancelConsultationNumber',
                'smsApiToken',
                'vpozSignatureAppHost',
                'smsapi2wayNumber',
                'smsNumberUs',
                false,
                $this->entityManagerMock,
                $this->phoneNumberManagerMock,
                $this->routerMock,
                $this->loggerSMSMock,
                $this->clinicTranslationManagerMock,
                $this->consultationLogManagerMock,
                $this->emailMock,
                $this->clinicManagerMock,
                $this->parameterBagMock,
                $this->kernelMock,
                $this->languageExtensionMock,
                $this->mediaServerMock,
                $this->peselAuthenticationToConsultationLinkMock,
                $this->examCartServiceMock,
                $this->smsActionLinkGeneratorMock,
                $this->notificationServiceMock,
                $this->medicalDocumentationMagicLinkHandlerMock,
                $this->urlResolverMock,
                $this->whatsAppNotificationsServiceMock,
                $this->passwordlessMagicLinkGeneratorMock,
                $this->messageBusMock,
                $this->specializationTranslationServiceMock,
                $this->visitsCancellationServiceMock,
                $this->createConsultationLogHandler,
                $this->consultationRepository])
            ->onlyMethods($mockedMethods)
            ->getMock();
    }

    private function getUserThatCanBeSentSmsMock()
    {
        $phoneNumberString = '+48501635383';
        $phoneNumberObject = PhoneNumberUtil::getInstance()->parse($phoneNumberString);
        $userMock = $this->createMock(User::class);
        $userMock->method('getPhoneNumber')->willReturn($phoneNumberObject);
        $userMock->method('getReadablePhoneNumber')->willReturn($phoneNumberString);
        return $userMock;
    }
}