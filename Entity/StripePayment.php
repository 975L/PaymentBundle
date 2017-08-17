<?php
/*
 * (c) 2017: 975l <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="stripe_payment")
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity
 */
class StripePayment
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var integer
     *
     * @ORM\Column(name="finished", type="integer", nullable=true)
     */
    protected $finished;

    /**
     * @var string
     *
     * @ORM\Column(name="order_id", type="string", nullable=true)
     */
    protected $orderId;

    /**
     * @var integer
     *
     * @ORM\Column(name="amount", type="integer", nullable=true)
     */
    protected $amount;

    /**
     * @var string
     *
     * @ORM\Column(name="description", type="string", nullable=true)
     */
    protected $description;

    /**
     * @var string
     *
     * @ORM\Column(name="currency", type="string", nullable=true)
     */
    protected $currency;

    /**
     * @var string
     *
     * @ORM\Column(name="action", type="string", nullable=true)
     */
    protected $action;

    /**
     * @var integer
     *
     * @ORM\Column(name="stripe_fee", type="integer", nullable=true)
     */
    protected $stripeFee;

    /**
     * @var string
     *
     * @ORM\Column(name="stripe_token", type="string", nullable=true)
     */
    protected $stripeToken;

    /**
     * @var string
     *
     * @ORM\Column(name="stripe_token_type", type="string", nullable=true)
     */
    protected $stripeTokenType;

    /**
     * @var string
     *
     * @ORM\Column(name="stripe_email", type="string", nullable=true)
     */
    protected $stripeEmail;

    /**
     * @var integer
     *
     * @ORM\Column(name="user_id", type="integer", nullable=true)
     */
    protected $userId;

    /**
     * @var string
     *
     * @ORM\Column(name="user_ip", type="string", nullable=true)
     */
    protected $userIp;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="creation", type="datetime", nullable=true)
     */
    protected $creation;


    public function __construct($data, $timezone)
    {
        $now = \DateTime::createFromFormat('U.u', microtime(true));
        if ($timezone !== null) {
            $now->setTimeZone(new \DateTimeZone($timezone));
        }
        $this->setOrderId($now->format('Ymd-His-u'));
        $this->setCreation($now);
        $this->setDataFromArray($data);
    }


    public function setDataFromArray($data)
    {
        foreach ($data as $key => $value) {
            $function = 'set' . ucfirst($key);
            $this->$function($value);
        }
    }


    /**
     * Set id
     *
     * @param integer $id
     */
    public function setId()
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set finished
     *
     * @param integer $finished
     *
     * @return StripePayment
     */
    public function setFinished($finished)
    {
        $this->finished = $finished;

        return $this;
    }

    /**
     * Get finished
     *
     * @return integer
     */
    public function getFinished()
    {
        return $this->finished == 1 ? true : false;
    }

    /**
     * Set orderId
     *
     * @param string $orderId
     *
     * @return StripePayment
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * Get orderId
     *
     * @return string
     */
    public function getOrderId()
    {
        return $this->orderId;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     *
     * @return StripePayment
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Get amount
     *
     * @return integer
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Set description
     *
     * @param string $description
     *
     * @return StripePayment
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set currency
     *
     * @param string $currency
     *
     * @return StripePayment
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Get currency
     *
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * Set action
     *
     * @param string $action
     *
     * @return StripePayment
     */
    public function setAction($action)
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Get action
     *
     * @return string
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Set stripeFee
     *
     * @param integer $stripeFee
     *
     * @return StripePayment
     */
    public function setStripeFee($stripeFee)
    {
        $this->stripeFee = $stripeFee;

        return $this;
    }

    /**
     * Get stripeFee
     *
     * @return integer
     */
    public function getStripeFee()
    {
        return $this->stripeFee;
    }

    /**
     * Set stripeToken
     *
     * @param string $stripeToken
     *
     * @return StripePayment
     */
    public function setStripeToken($stripeToken)
    {
        $this->stripeToken = $stripeToken;

        return $this;
    }

    /**
     * Get stripeToken
     *
     * @return string
     */
    public function getStripeToken()
    {
        return $this->stripeToken;
    }

    /**
     * Set stripeTokenType
     *
     * @param string $stripeTokenType
     *
     * @return StripePayment
     */
    public function setStripeTokenType($stripeTokenType)
    {
        $this->stripeTokenType = $stripeTokenType;

        return $this;
    }

    /**
     * Get stripeTokenType
     *
     * @return string
     */
    public function getStripeTokenType()
    {
        return $this->stripeTokenType;
    }

    /**
     * Set stripeEmail
     *
     * @param string $stripeEmail
     *
     * @return StripePayment
     */
    public function setStripeEmail($stripeEmail)
    {
        $this->stripeEmail = $stripeEmail;

        return $this;
    }

    /**
     * Get stripeEmail
     *
     * @return string
     */
    public function getStripeEmail()
    {
        return $this->stripeEmail;
    }

    /**
     * Set userId
     *
     * @param integer $userId
     *
     * @return StripePayment
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Get userId
     *
     * @return integer
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * Set userIp
     *
     * @param string $userIp
     *
     * @return StripePayment
     */
    public function setUserIp($userIp)
    {
        $this->userIp = $userIp;

        return $this;
    }

    /**
     * Get userIp
     *
     * @return string
     */
    public function getUserIp()
    {
        return $this->userIp;
    }

    /**
     * Set creation
     *
     * @param \DateTime $creation
     *
     * @return StripePayment
     */
    public function setCreation($creation)
    {
        $this->creation = $creation;

        return $this;
    }

    /**
     * Get creation
     *
     * @return \DateTime
     */
    public function getCreation()
    {
        return $this->creation;
    }
}