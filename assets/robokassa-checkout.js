class RobokassaCheckout {
    #cdnUrl = 'https://js.robokassa.co/v2/inline.js';
    #publicKey = null;
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.submitButton = window.fluentcart_checkout_vars?.submit_button;
        this.#publicKey = response?.payment_args?.public_key;
    }

     init() {
        this.paymentLoader.enableCheckoutButton(this.translate(this.submitButton.text));
        const that = this;        
        const robokassaContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_robokassa');
        if (robokassaContainer) {
            robokassaContainer.innerHTML = '';
        }

        this.renderPaymentInfo();


        this.#publicKey = this.data?.payment_args?.public_key;

        window.addEventListener("fluent_cart_payment_next_action_robokassa", async(e) => {

            const remoteResponse = e.detail?.response;           
            const access_code = remoteResponse?.data?.robokassa_data?.access_code;
            const authorizationUrl = remoteResponse?.data?.robokassa_data?.authorization_url;
            const intent = remoteResponse?.data?.intent;

             if (access_code && authorizationUrl) {
                // Скрыть загрузчик платежа
                if (intent === 'onetime') {
                    this.onetimePaymentHandler(access_code, authorizationUrl);
                } else if (intent === 'subscription') {
                    this.robokassaSubscriptionPayment(access_code, authorizationUrl);
                }
             }
               
        });
    }

    translate(string) {
        const translations = window.fct_robokassa_data?.translations || {};
        return translations[string] || string;
    }

    renderPaymentInfo() {
        let html = '<div class="fct-robokassa-info">';
        
        // Простой заголовок
        html += '<div class="fct-robokassa-header">';
        html += '<p class="fct-robokassa-subheading">' + this.$t('Available payment methods on Checkout') + '</p>';
        html += '</div>';
        
        // Способы оплаты
        html += '<div class="fct-robokassa-methods">';
        html += '<div class="fct-robokassa-method">';
        html += '<span class="fct-method-name">' + this.$t('Cards') + '</span>';
        html += '</div>';
        html += '<div class="fct-robokassa-method">';
        html += '<span class="fct-method-name">' + this.$t('Bank Transfer') + '</span>';
        html += '</div>';
        html += '<div class="fct-robokassa-method">';
        html += '<span class="fct-method-name">' + this.$t('USSD') + '</span>';
        html += '</div>';
        html += '<div class="fct-robokassa-method">';
        html += '<span class="fct-method-name">' + this.$t('QR Code') + '</span>';
        html += '</div>';
        html += '<div class="fct-robokassa-method">';
        html += '<span class="fct-method-name">' + this.$t('PayAttitude') + '</span>'; 
        html += '</div>';
        html += '</div>';
        
        html += '</div>';
        
        // Добавить CSS стили
        html += `<style>
            .fct-robokassa-info {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #f9f9f9;
                margin-bottom: 20px;
            }
            
            .fct-robokassa-header {
                text-align: center;
                margin-bottom: 16px;
            }
            
            .fct-robokassa-heading {
                margin: 0 0 4px 0;
                font-size: 18px;
                font-weight: 600;
                color: #0c7fdc;
            }
            
            .fct-robokassa-subheading {
                margin: 0;
                font-size: 12px;
                color: #999;
                font-weight: 400;
            }
            
            .fct-robokassa-methods {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 10px;
            }
            
            .fct-robokassa-method {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                transition: all 0.2s ease;
                cursor: text;
            }
            
            .fct-method-name {
                font-size: 12px;
                font-weight: 500;
                color: #333;
            }
            
            @media (max-width: 768px) {
                .fct-robokassa-info {
                    padding: 16px;
                }
                
                .fct-robokassa-heading {
                    font-size: 16px;
                }
                
                .fct-robokassa-methods {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                
                .fct-robokassa-method {
                    padding: 8px;
                }
            }
        </style>`;

        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_robokassa');
        container.innerHTML = html;
    }

    loadRobokassaScript() {
        return new Promise((resolve, reject) => {
            if (typeof RobokassaPop !== 'undefined') {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = this.#cdnUrl;
            script.onload = () => {
                resolve();
            };
            script.onerror = () => {
                reject(new Error('Failed to load Robokassa script'));
            };

            document.head.appendChild(script);
        });
    }

    async onetimePaymentHandler(access_code, authorizationUrl) {
         try {
            await this.loadRobokassaScript();
        } catch (error) {
            console.error('Скрипт Robokassa не загрузился:', error);
            this.handleRobokassaError(error);
            return;
        }

        try {
            const popup = new RobokassaPop();
            
            // Настроить обработчики событий жизненного цикла транзакции Robokassa
            popup.resumeTransaction(access_code, {
                onSuccess: (transaction) => {
                    this.handlePaymentSuccess(transaction);
                },
                onCancel: () => {
                    this.handlePaymentCancel();
                },
                onError: (error) => {
                    this.handleRobokassaError(error);
                }
            });
            
        } catch (error) {
            console.error('Ошибка при запуске всплывающего окна Robokassa:', error);
            this.handleRobokassaError(error);
        }
    }

    async robokassaSubscriptionPayment(access_code, authorizationUrl) {
        try {
            await this.loadRobokassaScript();
        } catch (error) {
            console.error('Скрипт Robokassa не загрузился:', error);
            this.handleRobokassaError(error);
            return;
        }

        try {
            const popup = new RobokassaPop();
            
            // Настроить обработчики событий для подписки Robokassa
            popup.resumeTransaction(access_code, {
                onSuccess: (transaction) => {
                    console.log('успешно: ', transaction)
                    this.handlePaymentSuccess(transaction);
                },
                onCancel: () => {
                    this.handlePaymentCancel();
                },
                onError: (error) => {
                    this.handleRobokassaError(error);
                }
            });
            
        } catch (error) {
            console.error('Ошибка при запуске всплывающего окна подписки Robokassa:', error);
            this.handleRobokassaError(error);
        }
    }

    handlePaymentSuccess(transaction) {

        const params = new URLSearchParams({
            action: 'fluent_cart_confirm_robokassa_payment',
            reference: transaction.reference || transaction.trxref,
            trx_id: transaction.trans || transaction.transaction,
            robokassa_fct_nonce: window.fct_robokassa_data?.nonce
        });

        const that = this;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res?.redirect_url) {
                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus('redirecting');
                        window.location.href = res.redirect_url;
                    } else {
                        that.handleRobokassaError(new Error(res?.message || 'Подтверждение платежа не удалось'));
                    }
                } catch (error) {
                    that.handleRobokassaError(error);
                }
            } else {
                that.handleRobokassaError(new Error(that.$t('Ошибка сети: ' + xhr.status)));
            }
        };

        xhr.onerror = function () {
            try {
                const err = JSON.parse(xhr.responseText);
                that.handleRobokassaError(err);
            } catch (e) {
                console.error('Произошла ошибка:', e);
                that.handleRobokassaError(e);
            }
        };

        xhr.send(params.toString());
    }

    handlePaymentCancel() {
        this.paymentLoader.hideLoader();
        this.paymentLoader.enableCheckoutButton(this.submitButton.text);    
    }

    handleRobokassaError(err) {
        let errorMessage = this.$t('Произошла неизвестная ошибка');

        if (err?.message) {
            try {
                const jsonMatch = err.message.match(/{.*}/s);
                if (jsonMatch) {
                    errorMessage = JSON.parse(jsonMatch[0]).message || errorMessage;
                } else {
                    errorMessage = err.message;
                }
            } catch {
                errorMessage = err.message || errorMessage;
            }
        }

        let robokassaContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_robokassa');
        let tempMessage = this.$t('Что-то пошло не так');

        if (robokassaContainer) {            
            robokassaContainer.innerHTML += '<div id="fct_loading_payment_processor">' + this.$t(tempMessage) + '</div>';
            robokassaContainer.style.display = 'block';
            robokassaContainer.querySelector('#fct_loading_payment_processor').style.color = '#dc3545';
            robokassaContainer.querySelector('#fct_loading_payment_processor').style.fontSize = '14px';
            robokassaContainer.querySelector('#fct_loading_payment_processor').style.padding = '10px';
        }
         
        this.paymentLoader.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Оформить заказ'));
    
    }

}

window.addEventListener("fluent_cart_load_payments_robokassa", function (e) {
    const translate = window.fluentcart.$t;
    addLoadingText();
    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message);
            return;
        }
        new RobokassaCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_robokassa_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        let message = error?.message || $t('Произошла ошибка при загрузке Robokassa.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.style.padding = '10px';
        errorDiv.style.fontSize = '14px';
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;

        const robokassaContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_robokassa');
        if (robokassaContainer) {
            robokassaContainer.appendChild(errorDiv);
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
        return;
    }

    function addLoadingText() {
        let robokassaButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_robokassa');
        if (robokassaButtonContainer) {
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            const translations = window.fct_robokassa_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Загрузка процессора оплаты...');
            robokassaButtonContainer.appendChild(loadingMessage);
        }
    }
});