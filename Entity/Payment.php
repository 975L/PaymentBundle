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
 * @ORM\Entity(repositoryClass="c975L\PaymentBundle\Repository\PaymentRepository")
 */
class Payment
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(name="finished", type="integer", nullable=true)
     */
    protected $finished;

    /**
     * @ORM\Column(name="order_id", type="string", nullable=true)
     */
    protected $orderId;

    /**
     * @ORM\Column(name="amount", type="integer", nullable=true)
     */
    protected $amount;

    /**
     * @ORM\Column(name="vat", type="integer", nullable=true)
     */
    protected $vat;

    /**
     * @ORM\Column(name="description", type="string", nullable=true)
     */
    protected $description;

    /**
     * @ORM\Column(name="currency", type="string", nullable=true)
     */
    protected $currency;

    /**
     * @ORM\Column(name="action", type="string", nullable=true)
     */
    protected $action;

    /**
     * @ORM\Column(name="stripe_fee", type="integer", nullable=true)
     */
    protected $stripeFee;

    /**
     * @ORM\Column(name="stripe_token", type="string", nullable=true)
     */
    protected $stripeToken;

    /**
     * @ORM\Column(name="stripe_token_type", type="string", nullable=true)
     */
    protected $stripeTokenType;

    /**
     * @ORM\Column(name="stripe_email", type="string", nullable=true)
     */
    protected $stripeEmail;

    /**
     * @ORM\Column(name="user_id", type="integer", nullable=true)
     */
    protected $userId;

    /**
     * @ORM\Column(name="user_ip", type="string", nullable=true)
     */
    protected $userIp;

    /**
     * @ORM\Column(name="creation", type="datetime", nullable=true)
     */
    protected $creation;

    protected $live;
    protected $returnRoute;

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
            $method = 'set' . ucfirst($key);
            if (method_exists($this, $method)) {
                $this->$method($value);
            }
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
     * @return integer
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
     * @return Payment
     */
    public function setFinished($finished)
    {
        $this->finished = $finished;

        return $this;
    }

    /**
     * Get finished
     *
     * @return boolean
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
     * @return Payment
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
     * @return Payment
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
     * Set vat
     *
     * @param integer $vat
     *
     * @return Payment
     */
    public function setVat($vat)
    {
        $this->vat = $vat;

        return $this;
    }

    /**
     * Get vat
     *
     * @return integer
     */
    public function getVat()
    {
        return $this->vat;
    }

    /**
     * Set description
     *
     * @param string $description
     *
     * @return Payment
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
     * @return Payment
     */
    public function setCurrency($currency)
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    /**
     * Get currency
     *
     * @return string
     */
    public function getCurrency()
    {
        return strtoupper($this->currency);
    }

    /**
     * Set action
     *
     * @param string $action
     *
     * @return Payment
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
     * @return Payment
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
     * @return Payment
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
     * @return Payment
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
     * @return Payment
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
     * @return Payment
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
     * @return Payment
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
     * @return Payment
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

    /**
     * Set live
     *
     * @param boolean $live
     *
     * @return Payment
     */
    public function setLive($live)
    {
        $this->live = $live;

        return $this;
    }

    /**
     * Get live
     *
     * @return boolean
     */
    public function getLive()
    {
        return $this->live;
    }

    /**
     * Set returnRoute
     *
     * @param string $returnRoute
     *
     * @return Payment
     */
    public function setReturnRoute($returnRoute)
    {
        $this->returnRoute = $returnRoute;

        return $this;
    }

    /**
     * Get returnRoute
     *
     * @return string
     */
    public function getReturnRoute()
    {
        return $this->returnRoute;
    }
}