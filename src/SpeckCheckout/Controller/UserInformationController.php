<?php

namespace SpeckCheckout\Controller;

use SpeckCheckout\Strategy\Step\UserInformation;

use Zend\Http\PhpEnvironment\Response;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Stdlib\Parameters;
use Zend\View\Model\ViewModel;

class UserInformationController extends AbstractActionController
{

    public function validatePrgSegmentForm($segmentName, $infoForm, array $prg)
    {
        $segmentData = $prg[$segmentName];
        $form  = $infoForm->get($segmentName)->setData($segmentData);
        $valid = $form->isValid();

        $eventData = array(
            'prg'   => $prg,
            'form'  => $form,
            'valid' => $valid
        );
        $response = $this->getEventManager()->trigger(
            __FUNCTION__ . '.validate.' . $segmentName, $this, $eventData
        );
        $data = isset($response[0]) ? $response[0] : array();

        $form  = isset($data['form'])  ? $data['form']  : $form;
        $prg   = isset($data['prg'])   ? $data['prg']   : $prg;
        $valid = isset($data['valid']) ? $data['valid'] : $valid;

        return array(
            'valid' => $valid,
            'form'  => $form,
            'prg'   => $prg
        );
    }

    public function indexAction()
    {
        if ($this->zfcuserauthentication()->hasIdentity()) {
            return $this->redirect()->toRoute('checkout/user-information/addresses');
        }

        $form = $this->getRegisterForm();

        $prg = $this->prg('checkout/user-information');
        if ($prg instanceof Response) {
            return $prg;
        } else if ($prg === false) {
            return array(
                'form' => $form,
            );
        }

        $result   = $this->validatePrgSegmentForm('shipping', $form, $prg);
        $prg      = $result['prg'];
        $valid1   = $result['valid'];
        $shipping = $result['form'];

        $result   = $this->validatePrgSegmentForm('billing', $form, $prg);
        $prg      = $result['prg'];
        $valid2   = $result['valid'];
        $billing  = $result['form'];

        $result   = $this->validatePrgSegmentForm('user', $form, $prg);
        $prg      = $result['prg'];
        $valid3   = $result['valid'];
        $zfcuser  = $result['form'];

        $valid = $valid1 && $valid2 && $valid3;

        if (!$valid) {
            return array(
                'form' => $form,
            );
        }

        $user = $this->getServiceLocator()->get('zfcuser_user_service')->register($zfcuser->getData());

        // TODO: contact name
        $userAddressService = $this->getServiceLocator()->get('SpeckUserAddress\Service\UserAddress');
        $ship = $userAddressService->create($shipping->getData(), $user->getId());
        $bill = $userAddressService->create($billing->getData(), $user->getId());

        $checkoutService = $this->getServiceLocator()->get('SpeckCheckout\Service\Checkout');
        $strategy = $checkoutService->getCheckoutStrategy();
        $strategy->setShippingAddress($ship);
        $strategy->setBillingAddress($bill);
        $strategy->setEmailAddress($user->getEmail());

        foreach ($strategy->getSteps() as $step) {
            if ($step instanceof UserInformation) {
                $step->setComplete(true);
                break;
            }
        }

        $post['identity'] = $user->getEmail();
        $post['credential'] = $prg['zfcuser']['password'];
        $post['redirect'] = $this->url()->fromRoute('checkout');

        $this->getRequest()->setPost(new Parameters($post));

        return $this->forward()->dispatch('zfcuser', array(
            'action'   => 'authenticate',
        ));
    }

    public function pickAddressesAction()
    {
        $userAddressService = $this->getServiceLocator()->get('SpeckUserAddress\Service\UserAddress');

        $prg = $this->prg('checkout/user-information/addresses');
        if ($prg instanceof Response) {
            return $prg;
        }

        $addresses = $userAddressService->getAddresses()->toArray();

        $addressesArray = array();
        foreach ($addresses as $a) {
            $addressesArray[$a['address_id']] = $a;
        }

        $shippingAddressForm = $this->getServiceLocator()->get('SpeckAddress\Form\Address');
        $shippingAddressForm->setName('shipping')
            ->setInputFilter($this->getServiceLocator()->get('SpeckAddress\Form\AddressFilter'));

        $billingAddressForm = $this->getServiceLocator()->get('SpeckAddress\Form\Address');
        $billingAddressForm->setName('billing')
            ->setInputFilter($this->getServiceLocator()->get('SpeckAddress\Form\AddressFilter'));

        $form = new \Zend\Form\Form;

        $form->add($shippingAddressForm)
            ->add($billingAddressForm);

        $checkoutService = $this->getServiceLocator()->get('SpeckCheckout\Service\Checkout');

        if ($prg === false) {
            $strategy = $checkoutService->getCheckoutStrategy();
            $return = array(
                'addresses'    => $addressesArray,
                'form'         => $form,
            );
            if ($strategy->getShippingAddress()) {
                $return['ship_prefill'] = $strategy->getShippingAddress()->getAddressId();
            }
            if ($strategy->getBillingAddress()) {
                $return['bill_prefill'] = $strategy->getBillingAddress()->getAddressId();
            }
            return $return;
        }

        $shippingAddressId = isset($prg['shipping_address_id']) ? $prg['shipping_address_id'] : 0;
        $billingAddressId = isset($prg['billing_address_id']) ? $prg['billing_address_id'] : 0;

        $shipping = $form->get('shipping');
        $billing = $form->get('billing');

        $shipping->setData(isset($prg['shipping']) ? $prg['shipping'] : array());
        $billing->setData(isset($prg['billing']) ? $prg['billing'] : array());

        $valid1 = ($shippingAddressId != 0) ? true : $shipping->isValid();
        $valid2 = ($billingAddressId != 0) ? true : $billing->isValid();

        $valid = $valid1 && $valid2;

        if (!$valid) {
            return array(
                'ship_prefill' => $shippingAddressId,
                'bill_prefill' => $billingAddressId,
                'addresses' => $addressesArray,
                'form'      => $form,
            );
        }

        $addressService = $this->getServiceLocator()->get('SpeckAddress\Service\Address');
        $userAddressService = $this->getServiceLocator()->get('SpeckUserAddress\Service\UserAddress');

        $user = $this->zfcUserAuthentication()->getIdentity();

        if ($shippingAddressId == 0) {
            $ship = $userAddressService->create($shipping->getData());
        } else {
            $ship = $addressService->findById($shippingAddressId);
        }

        if ($billingAddressId == 0) {
            $bill = $userAddressService->create($billing->getData());
        } else {
            $bill = $addressService->findById($billingAddressId);
        }

        $strategy = $checkoutService->getCheckoutStrategy();
        $strategy->setShippingAddress($ship);
        $strategy->setBillingAddress($bill);
        $strategy->setEmailAddress($user->getEmail());

        foreach ($strategy->getSteps() as $step) {
            if ($step instanceof UserInformation) {
                $step->setComplete(true);
                break;
            }
        }

        return $this->redirect()->toRoute('checkout');
    }

    public function getRegisterForm()
    {
        $userForm = $this->getServiceLocator()->get('zfcuser_register_form');
        $userForm->setName('zfcuser')->setWrapElements(true);
        $userForm->remove('submit');

        $shippingAddressForm = $this->getServiceLocator()->get('SpeckAddress\Form\Address');
        $shippingAddressForm->setName('shipping')->setWrapElements(true)
            ->setInputFilter($this->getServiceLocator()->get('SpeckAddress\Form\AddressFilter'));

        $billingAddressForm = $this->getServiceLocator()->get('SpeckAddress\Form\Address');
        $billingAddressForm->setName('billing')->setWrapElements(true)
            ->setInputFilter($this->getServiceLocator()->get('SpeckAddress\Form\AddressFilter'));

        $form = new \Zend\Form\Form;

        $form->add($userForm)
            ->add($shippingAddressForm)
            ->add($billingAddressForm);

        return $form;
    }
}
