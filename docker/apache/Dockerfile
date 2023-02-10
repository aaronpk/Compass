FROM php:7.1-apache-jessie

RUN apt-get update --fix-missing

RUN apt-get -y install sudo &&\
    apt-get -y install curl &&\
    apt-get -y install git &&\
    apt-get -y install zip unzip &&\
    apt-get -y install vim && \
    apt-get -y install libicu-dev

RUN docker-php-ext-configure intl

RUN docker-php-ext-install pdo pdo_mysql mysqli intl

RUN curl -sS https://getcomposer.org/installer | php && chmod +x composer.phar && mv composer.phar /usr/local/bin/composer



RUN a2enmod rewrite

RUN echo "ServerName compass" | sudo tee /etc/apache2/conf-available/servername.conf && a2enconf servername

COPY ./000-default.conf /etc/apache2/sites-enabled/000-default.conf

RUN apachectl graceful