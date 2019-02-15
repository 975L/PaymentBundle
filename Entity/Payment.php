<?php
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Entity;

use DateTime;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity Event (linked to DB table `stripe_payment`)
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2017 975L <contact@975l.com>
 *
 * @ORM\Table(name="stripe_payment")
 * @ORM\HasLifecycleCallbacks()
 * @ORM\Entity(repositoryClass="c975L\PaymentBundle\Repository\PaymentRepository")
 */
class Payment
{
    /**
     * Payment unique id
     * @var int
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * If the Payment is finished
     * @var bool
     *
     * @ORM\Column(name="finished", type="integer", nullable=true)
     */
    protected $finished;

    /**
     * OrderId for the Payment
     * @var string
     *
     * @ORM\Column(name="order_id", type="string", length=48, nullable=true)
     */
    protected $orderId;

    /**
     * Amount in cents for the Payment
     * @var int
     *
     * @ORM\Column(name="amount", type="integer", nullable=true)
     */
    protected $amount;

    /**
     * VAT rate without decimal (x 100) for the Payment
     * @var int
     *
     * @ORM\Column(name="vat", type="integer", nullable=true)
     */
    protected $vat;

    /**
     * Description for the Payment
     * @var string
     *
     * @ORM\Column(name="description", type="string", length=512, nullable=true)
     */
    protected $description;

    /**
     * Currency for the Payment
     * @var string
     *
     * @ORM\Column(name="currency", type="string", length=3, nullable=true)
     */
    protected $currency;

    /**
     * Action to be executed after the payment
     * @var string
     *
     * @ORM\Column(name="action", type="string", length=128, nullable=true)
     */
    protected $action;

    /**
     * Estimated Stripe fee in cents
     * @var int
     *
     * @ORM\Column(name="stripe_fee", type="integer", nullable=true)
     */
    protected $stripeFee;

    /**
     * Stripe token
     * @var string
     *
     * @ORM\Column(name="stripe_token", type="string", length=128, nullable=true)
     */
    protected $stripeToken;

    /**
     * Stripe token type
     * @var string
     *
     * @ORM\Column(name="stripe_token_type", type="string", length=16, nullable=true)
     */
    protected $stripeTokenType;

    /**
     * Email used for Stripe Payment
     * @var string
     *
     * @ORM\Column(name="stripe_email", type="string", length=255, nullable=true)
     */
    protected $stripeEmail;

    /**
     * User unique id (if logged in)
     * @var int
     *
     * @ORM\Column(name="user_id", type="integer", nullable=true)
     */
    protected $userId;

    /**
     * User IP address
     * @var string
     *
     * @ORM\Column(name="user_ip", type="string", length=48, nullable=true)
     */
    protected $userIp;

    /**
     * DateTime creation for the Payment
     * @var DateTime
     *
     * @ORM\Column(name="creation", type="datetime", nullable=true)
     */
    protected $creation;

    /**
     * Wether or not the payments are live (not mapped)
     * @var bool
     */
    protected $live;

    /**
     * Return Route to be used after payment (not mapped)
     * @var string
     */
    protected $returnRoute;

    public function __construct($data, $timezone)
    {
        $now = DateTime::createFromFormat('U.u', microtime(true));
        if ($timezone !== null) {
            $now->setTimeZone(new DateTimeZone($timezone));
        }
        $this->setOrderId($now->format('Ymd-His-u'));
        $this->setCreation($now);
        $this->setDataFromArray($data);
    }

    /**
     * Hydrates entity from associative array
     * @param array $data
     */
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
     * Get id
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Set finished
     * @param bool
     * @return Payment
     */
    public function setFinished(?bool $finished)
    {
        $this->finished = $finished;

        return $this;
    }

    /**
     * Get finished
     * @return bool
     */
    public function getFinished(): ?bool
    {
        return $this->finished == 1 ? true : false;
    }

    /**
     * Set orderId
     * @param string
     * @return Payment
     */
    public function setOrderId(?string $orderId)
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * Get orderId
     * @return string
     */
    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    /**
     * Set amount
     * @param int
     * @return Payment
     */
    public function setAmount(?int $amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Get amount
     * @return int
     */
    public function getAmount(): ?int
    {
        return $this->amount;
    }

    /**
     * Set vat
     * @param integer
     * @return Payment
     */
    public function setVat(?int $vat)
    {
        $this->vat = $vat;

        return $this;
    }

    /**
     * Get vat
     * @return int
     */
    public function getVat(): ?int
    {
        return $this->vat;
    }

    /**
     * Set description
     * @param string
     * @return Payment
     */
    public function setDescription(?string $description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description
     * @return string
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Set currency
     * @param string
     * @return Payment
     */
    public function setCurrency(?string $currency)
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    /**
     * Get currency
     * @return string
     */
    public function getCurrency(): ?string
    {
        return strtoupper($this->currency);
    }

    /**
     * Set action
     * @param string
     * @return Payment
     */
    public function setAction(?string $action)
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Get action
     * @return string
     */
    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * Set stripeFee
     * @param int
     * @return Payment
     */
    public function setStripeFee(?int $stripeFee)
    {
        $this->stripeFee = $stripeFee;

        return $this;
    }

    /**
     * Get stripeFee
     * @return int
     */
    public function getStripeFee(): int
    {
        return $this->stripeFee;
    }

    /**
     * Set stripeToken
     * @param string
     * @return Payment
     */
    public function setStripeToken(?string $stripeToken)
    {
        $this->stripeToken = $stripeToken;

        return $this;
    }

    /**
     * Get stripeToken
     * @return string
     */
    public function getStripeToken(): ?string
    {
        return $this->stripeToken;
    }

    /**
     * Set stripeTokenType
     * @param string
     * @return Payment
     */
    public function setStripeTokenType(?string $stripeTokenType)
    {
        $this->stripeTokenType = $stripeTokenType;

        return $this;
    }

    /**
     * Get stripeTokenType
     * @return string
     */
    public function getStripeTokenType(): ?string
    {
        return $this->stripeTokenType;
    }

    /**
     * Set stripeEmail
     * @param string
     * @return Payment
     */
    public function setStripeEmail(?string $stripeEmail)
    {
        $this->stripeEmail = $stripeEmail;

        return $this;
    }

    /**
     * Get stripeEmail
     * @return string
     */
    public function getStripeEmail(): ?string
    {
        return $this->stripeEmail;
    }

    /**
     * Set userId
     * @param int
     * @return Payment
     */
    public function setUserId(?int $userId)
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Get userId
     * @return int
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Set userIp
     * @param string
     * @return Payment
     */
    public function setUserIp(?string $userIp)
    {
        $this->userIp = $userIp;

        return $this;
    }

    /**
     * Get userIp
     * @return string
     */
    public function getUserIp(): ?string
    {
        return $this->userIp;
    }

    /**
     * Set creation
     * @param DateTime
     * @return Payment
     */
    public function setCreation(?DateTime $creation)
    {
        $this->creation = $creation;

        return $this;
    }

    /**
     * Get creation
     * @return DateTime
     */
    public function getCreation(): ?DateTime
    {
        return $this->creation;
    }

    /**
     * Set live
     * @param bool
     * @return Payment
     */
    public function setLive(?bool $live)
    {
        $this->live = $live;

        return $this;
    }

    /**
     * Get live
     * @return bool
     */
    public function getLive(): ?bool
    {
        return $this->live;
    }

    /**
     * Set returnRoute
     * @param string
     * @return Payment
     */
    public function setReturnRoute(?string $returnRoute)
    {
        $this->returnRoute = $returnRoute;

        return $this;
    }

    /**
     * Get returnRoute
     * @return string
     */
    public function getReturnRoute(): ?string
    {
        return $this->returnRoute;
    }
}
