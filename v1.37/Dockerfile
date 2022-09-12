FROM mediawiki:1.37.1
# FROM mediawiki:latest

# Define the ResourceBasePath in MediaWiki as a variable name: ResourceBasePath
ENV ResourceBasePath /var/www/html
# 
ARG BUILD_SMW

# Copy extensions folder
COPY ./extensions/ ${ResourceBasePath}/extensions/

# Make sure that existing software are updated 
RUN apt-get update -y
RUN apt-get install -y lua5.1
RUN apt-get install -y zip unzip

RUN apt update -y
# RUN apt install -y php7.4-curl

RUN apt-get install -y npm

# RUN apt-get update && apt-get install -y libmcrypt-dev \
#     libmagickwand-dev --no-install-recommends \
#     && pecl install imagick \
#     && docker-php-ext-enable imagick \
#     && docker-php-ext-install mcrypt pdo_mysql

# RUN apt-get install -y composer
RUN apt-get install -y git
RUN apt-get install -y python
RUN apt-get install -y libcairo2-dev libjpeg-dev libgif-dev

# To support 3D Extension
RUN apt-get install -y xvfb

# Installing correct composer version
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer --version=2.1.6

# get composer.local.json file
COPY ./composer.local.json ${ResourceBasePath}/composer.local.json

# Run to install SMW 4.0
RUN composer install
RUN composer update --no-dev

# # Copy this folder
# git clone https://gerrit.wikimedia.org/r/3d2png
# cd 3d2png
WORKDIR ${ResourceBasePath}/3d2png
RUN npm install

# for Widgets
# cd to /Widgets
WORKDIR ${ResourceBasePath}/extensions/Widgets
RUN composer update --no-dev
RUN chown -R www-data:www-data  ./compiled_templates/

# for 3D
WORKDIR ${ResourceBasePath}/extensions/3D
RUN composer update --no-dev
RUN composer install

# copy skins
COPY ./skins/MinervaNeue ${ResourceBasePath}/skins/MinervaNeue
COPY ./skins/MinervaNeue ${ResourceBasePath}/skins/Vector


# copy logos
COPY ./resources/xlp.png ${ResourceBasePath}/resources/assets/xlp.png
COPY ./resources/EuMuse.png ${ResourceBasePath}/resources/assets/EuMuse.png
COPY ./resources/toyhouse.png ${ResourceBasePath}/resources/assets/toyhouse.png
COPY ./resources/by-sa.png ${ResourceBasePath}/resources/assets/by-sa.png
COPY ./resources/aqua.png ${ResourceBasePath}/resources/assets/aqua.png

# Close, at workdir
WORKDIR ${ResourceBasePath}

# # 👉 Security: do not use the `root` user.
# ENV USER=node

# # --> relate to 3d 
# # You can not use `${USER}` here, but reference `/home/node`.
# ENV PATH="/home/node/.npm-global/bin:${PATH}"
# # 👉 The `--global` install dir
# ENV NPM_CONFIG_PREFIX="/home/node/.npm-global"

# # All subsequent commands are run as the `node` user.
# USER "${USER}"

# # Pre-create the target dir for global install.
# RUN mkdir -p "${NPM_CONFIG_PREFIX}/lib"

# WORKDIR /usr/src/app

# COPY package.json package-lock.json ./

# # 👉 Configure NPM, so pkg get installed with correct credentials.
# # Avoids `chmod u+x $DIR` and other workarounds.
# RUN npm --global config set user "${USER}" \
#     && npm --global --quiet --no-progress install \
#     && npm cache clean --force
