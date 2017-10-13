<?php
namespace Mautic\CustomBundle\EventListener;

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CustomBundle\Model\EmailLogModel;
use Mautic\EmailBundle\Swiftmailer\Message\MauticMessage;
use Psr\Log\LoggerInterface;
use Swift_Events_SendEvent;
use Swift_Events_SendListener;
use Swift_Events_TransportChangeEvent;
use Swift_Events_TransportChangeListener;

class GlobalEmailListener implements Swift_Events_SendListener, Swift_Events_TransportChangeListener
{
    /*
     * @var LoggerInterface
     */
    protected $logger;

    private $localDomain;

    public function __construct(LoggerInterface $logger, CoreParametersHelper $coreParametersHelper)
    {
        $this->logger = $logger;
        $this->localDomain = $coreParametersHelper->getParameter("mailer_helo_hostname");
    }

    /**
     * Invoked immediately before the Message is sent.
     *
     * @param Swift_Events_SendEvent $evt
     */
    public function beforeSendPerformed(Swift_Events_SendEvent $evt)
    {
        $message = $evt->getMessage();
        $this->customizeMessageHeaders($message);
    }

    /**
     * Invoked immediately after the Message is sent.
     *
     * @param Swift_Events_SendEvent $evt
     */
    public function sendPerformed(Swift_Events_SendEvent $evt)
    {
        /*
         * todo fix this
         * $this->em is null in the EmailLogModel, probably needs EmailLog to be known to ORM..
         * look at other bundle models for examples
         *
        $msg = $evt->getMessage();

        $log = new EmailLogModel();
        $log->writeLog(['sender' => $msg->getFrom(), 'recipient' => implode(',', $msg->getTo())]);
        */
    }

    /**
     * Invoked just before a Transport is started.
     *
     * @param Swift_Events_TransportChangeEvent $evt
     */
    public function beforeTransportStarted(Swift_Events_TransportChangeEvent $evt)
    {
        $transport = $evt->getTransport();

        if ($transport instanceof \Swift_Transport_EsmtpTransport  && !empty($this->localDomain)) {
            $transport->setLocalDomain($this->localDomain);
            $this->logger->debug("set LocalDomain to custom value", [ 'localDomain' => $this->localDomain ]);
        }
    }

    /**
     * Invoked immediately after the Transport is started.
     *
     * @param Swift_Events_TransportChangeEvent $evt
     */
    public function transportStarted(Swift_Events_TransportChangeEvent $evt)
    {
    }

    /**
     * Invoked just before a Transport is stopped.
     *
     * @param Swift_Events_TransportChangeEvent $evt
     */
    public function beforeTransportStopped(Swift_Events_TransportChangeEvent $evt)
    {
    }

    /**
     * Invoked immediately after the Transport is stopped.
     *
     * @param Swift_Events_TransportChangeEvent $evt
     */
    public function transportStopped(Swift_Events_TransportChangeEvent $evt)
    {
    }


    protected function createThreadIndex() {
        $t = time();
        $ft = ($t * 10000000) + 116444736000000000;

        // convert to hex and 0-pad to 8 bytes
        $ft_hex = base_convert($ft, 10, 16);
        $ft_hex = str_pad($ft_hex, 16, 0, STR_PAD_LEFT);

        // this is what determines the threading, so should be unique per thread
        $guid = md5(openssl_random_pseudo_bytes(256));

        // combine first 6 bytes of timestamp with hashed guid, convert to bin, then encode
        $thread_ascii = substr($ft_hex, 0, 12) . $guid;
        $thread_bin = hex2bin($thread_ascii);
        $thread_enc = base64_encode($thread_bin);

        return $thread_enc;
    }

    /**
     * @param $message \Swift_Message
     */
    protected function customizeMessageHeaders(\Swift_Message $message)
    {
        $threadIndex = $this->createThreadIndex();
        $seed = strtoupper(bin2hex(substr(base64_decode($threadIndex), 0, 3)));

        // generate boundary using initial bytes of Thread-Index
        $boundary = '----=_NextPart_' . '000' . '_' . '02A1' . '_' . $seed . '.' . sprintf('%08X', @array_pop(unpack('V', openssl_random_pseudo_bytes(4))));

        $message->getHeaders()->defineOrdering([
            'From',
            'To',
            'Subject',
            'Thread-Topic',
            'Thread-Index',
            'Date',
            'Message-ID',
            'Accept-Language',
            'Content-Language',
            'Content-Type',
            'MIME-Version'
        ]);

        $message->setBoundary($boundary);

        $message->setId(implode('@', [
            strtoupper(md5(getmypid().'.'.time().'.'.uniqid(mt_rand(), true))),
            $this->localDomain
        ]));
    }
}