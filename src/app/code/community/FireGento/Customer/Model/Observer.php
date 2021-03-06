<?php
/**
 * This file is part of a FireGento e.V. module.
 *
 * This FireGento e.V. module is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 3 as
 * published by the Free Software Foundation.
 *
 * This script is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * PHP version 5
 *
 * @category  FireGento
 * @package   FireGento_Customer
 * @author    FireGento Team <team@firegento.com>
 * @copyright 2013 FireGento Team (http://www.firegento.com)
 * @license   http://opensource.org/licenses/gpl-3.0 GNU General Public License, version 3 (GPLv3)
 */
/**
 * Observer for password validations
 *
 * @category FireGento
 * @package  FireGento_Customer
 * @author   FireGento Team <team@firegento.com>
 */
class FireGento_Customer_Model_Observer
{
    const XML_PATH_LOGIN_ATTEMPTS = 'customer/password/login_attempts';
    const XML_PATH_LOGIN_ATTEMPT_SPAN = 'customer/password/login_attempt_span';
    const XML_PATH_LOGIN_LOCK_TIME = 'customer/password/login_lock_time';
    const XML_PATH_CHECK_PASSWORD_MIN_LENGTH = 'customer/password/check_password_min_length';
    const XML_PATH_PASSWORD_MIN_LENGTH = 'customer/password/password_min_length';
    const XML_PATH_CHECK_PASSWORD_COMPLEXITY = 'customer/password/check_password_complexity';

    /**
     * Retrieve the helper class
     *
     * @return FireGento_Customer_Helper_Data Helper Object
     */
    protected function _getHelper()
    {
        return Mage::helper('firegento_customer');
    }

    /**
     * Retrieve customer session model object
     *
     * @return Mage_Customer_Model_Session Customer Session
     */
    protected function _getSession()
    {
        return Mage::getSingleton('customer/session');
    }

    /**
     * Retrieve the current website ID
     *
     * @return int Current Website ID
     */
    public function _getWebsiteId()
    {
        return Mage::app()->getWebsite()->getId();
    }

    /**
     * Validates the customer password upon save.
     *
     * @param Varien_Event_Observer $observer Observer Instance
     */
    public function customerSaveBefore(Varien_Event_Observer $observer)
    {
        $customer = $observer->getCustomer();
        $this->_validatePassword($customer->getEmail(), $customer->getPassword());
    }

    /**
     * Validates the customer before logging him in. A customer can be set to active/inactive
     * and he can be inactivated if user tries to login with wrong user credentials.
     *
     * Observer for controller_action_predispatch_customer_account_loginPost event.
     *
     * @param Varien_Event_Observer $observer Observer Instance
     */
    public function validateCustomerActivationBeforeLogin(Varien_Event_Observer $observer)
    {
        $controller = $observer->getControllerAction();

        try {
            $loginParams = $controller->getRequest()->getParam('login');
            if (isset($loginParams['username'])) {
                $validator = new Zend_Validate_EmailAddress();

                if ($validator->isValid($loginParams['username'])) {

                    // Load Customer
                    $customer = Mage::getModel('customer/customer')
                        ->setWebsiteId($this->_getWebsiteId())
                        ->loadByEmail($loginParams['username']);

                    // If user doesn't exist, throw exception
                    if (!$customer->getId()) {
                        throw new Exception(
                            $this->_getHelper()->__('Login failed.')
                        );
                    }

                    // check if customer given is valid and active
                    $this->_validateCustomerActivationStatus($customer);
                } else { // if ($validator->isValid($loginParams['username']))
                    throw new Exception(
                        $this->_getHelper()->__('The email address you entered is invalid.')
                    );
                }
            }
        } catch (Exception $e) {
            $this->_getSession()->addError($this->_getHelper()->__($e->getMessage()));

            // Set no-dispatch flag
            $controller->setFlag(
                $controller->getRequest()->getActionName(),
                Mage_Core_Controller_Varien_Action::FLAG_NO_DISPATCH,
                true
            );

            // Redirect to login page
            Mage::helper('firegento_customer/redirect')->_loginPostRedirect();
        }
    }

    /**
     * Validates if a customer account is active.
     *
     * @param Mage_Customer_Model_Customer $customer Customer Model
     * @throws FireGento_Customer_Exception
     */
    protected function _validateCustomerActivationStatus($customer)
    {
        $customerActive = $customer->getData('customer_active');
        if (!$customerActive) {
            throw new FireGento_Customer_Exception(
                $this->_getHelper()->__('Your account is currently not active.')
            );
        }

        /*
         * Check if the last failed login ban is over
         */

        $now          = time();
        $lockDuration = Mage::getStoreConfig(self::XML_PATH_LOGIN_LOCK_TIME);
        $lastAttempt  = $customer->getData('customer_last_login_failed');
        $loginAllowed = ($now - $lastAttempt > $lockDuration);
        if ($loginAllowed) {
            $customer->setData('customer_logins_failed', 0)
                ->setData('customer_last_login_failed', 0)
                ->save();
        }

        /*
         * Check if the login attempts reached the ban limit
         */

        $loginAttempts = $customer->getData('customer_logins_failed');
        $attemptLock   = $loginAttempts >= Mage::getStoreConfig(self::XML_PATH_LOGIN_ATTEMPTS);
        $timeLock      = ($now - $lastAttempt < $lockDuration);

        if (($attemptLock && $timeLock)) {
            throw new FireGento_Customer_Exception(
                $this->_getHelper()->__('Your account is locked due to too many failed login attempts.')
            );
        }
    }

    /**
     * Counts the number of failed logins of a current users.
     * Validate the controller postDispatch action of customer_account_loginPost.
     *
     * If user is not logged in at this point in time but login parameters given,
     * the user had wrong credentials -> so increment the number of failed logins.
     * If otherwise the user is logged in successfully, we can forget about
     * previous login failures.
     *
     * Observer for controller_action_postdispatch_customer_account_loginPost event
     *
     * @param Varien_Event_Observer $observer Observer Instance
     */
    public function countFailedLogins(Varien_Event_Observer $observer)
    {
        if (!$this->_getSession()->isLoggedIn()) {
            $loginParams = $observer->getControllerAction()->getRequest()->getParam('login');
            if (isset($loginParams) && isset($loginParams['username'])) {

                $validator = new Zend_Validate_EmailAddress();

                if ($validator->isValid($loginParams['username'])) {

                    // Load Customer
                    $customer = Mage::getModel('customer/customer')
                        ->setWebsiteId($this->_getWebsiteId())
                        ->loadByEmail($loginParams['username']);

                        // If customer exists, set new values..
                    if ($customer->getId()) {
                        $attempts    = $customer->getData('customer_logins_failed');
                        $lastAttempt = $customer->getData('customer_last_login_failed');
                        $now         = time();

                        if (!is_numeric($attempts)) {
                            $attempts = 1;
                        } else {
                            if ($now - $lastAttempt > Mage::getStoreConfig(self::XML_PATH_LOGIN_ATTEMPT_SPAN)) {
                                $attempts = 0;
                            }
                            $attempts++;
                        }

                        $customer->setData('customer_logins_failed', $attempts)
                            ->setData('customer_last_login_failed', $now)
                            ->save();
                    }
                }
            }
        } else {
            $customer = $this->_getSession()->getCustomer();
            $customer->setData('customer_logins_failed', 0)
                ->setData('customer_last_login_failed', 0)
                ->save();
        }
    }

    /**
     * Checks the password in checkout register mode
     *
     * Observer for controller_action_postdispatch_checkout_onepage_saveBilling event.
     *
     * @param  Varien_Event_Observer $observer Observer Instance
     * @return FireGento_Customer_Model_Observer Self.
     */
    public function checkPasswordStrengthAtOnepageRegistration(Varien_Event_Observer $observer)
    {
        $controllerAction = $observer->getControllerAction();

        /* @var $onepage Mage_Checkout_Model_Type_Onepage */
        $onepage = $controllerAction->getOnepage();

        // Check if customer wants to register
        $method = $onepage->getCheckoutMethod();
        if ($method != Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER) {
            return $this;
        }

        // Get email and password from request params
        $params = $observer->getControllerAction()->getRequest()->getParam('billing');
        $email = $params['email'];
        $password = $params['customer_password'];

        // Validate password and return error if invalid
        try {
            $this->_validatePassword($email, $password);
        } catch (FireGento_Customer_Exception $e) {
            $error = array(
                'error'   => -1,
                'message' => $e->getMessage(),
            );

            $controllerAction->getResponse()->setBody(
                Mage::helper('core')->jsonEncode($error)
            );
        }

        return $this;
    }

    /**
     * Validates the password against some common criterias. The validation is only
     * started, if a password was given within the running process.
     *
     * @param  string $email    Customer Email
     * @param  string $password Customer Password
     * @throws FireGento_Customer_Exception
     * @return FireGento_Customer_Model_Observer Self.
     */
    protected function _validatePassword($email, $password)
    {
        // Check if password is not empty
        if (!$password) {
            return $this;
        }

        // Password must not be equal to email
        if ($email == $password) {
            throw new FireGento_Customer_Exception(
                $this->_getHelper()->__('Your email address and your password can not be equal.')
            );
        }

        // Validate minimum password length
        if (Mage::getStoreConfigFlag(self::XML_PATH_CHECK_PASSWORD_MIN_LENGTH)) {
            $minLength = Mage::getStoreConfig(self::XML_PATH_PASSWORD_MIN_LENGTH);
            if (!$minLength || $minLength <= 0) {
                $minLength = 8;
            }

            if (strlen($password) <= $minLength) {
                throw new FireGento_Customer_Exception(
                    $this->_getHelper()->__('Your password length must be greater than %s.', $minLength)
                );
            }
        }

        // Validate password complexity
        if (Mage::getStoreConfigFlag(self::XML_PATH_CHECK_PASSWORD_COMPLEXITY)) {
            if (preg_match('/[A-Z]/', $password) == 0
                || preg_match('/[a-z]/', $password) == 0
                || preg_match('/[0-9]/', $password) == 0
            ) {
                $msg = 'Your password must contain at least one uppercase character, ';
                $msg .= 'one lowercase character and one digit.';
                throw new FireGento_Customer_Exception($this->_getHelper()->__($msg));
            }
        }
    }
}
