
# Get the most recent Git tag as the current version of this repo (needed for
# resolving what version of this repo the current files represent, since this
# is a sub-dependency of itself via ssp-deps).
VERSION=$(shell git describe --abbrev=0 --tags)


# Set up the default (i.e. - first) make entry.
start: web

addtestusers: migratedb
	docker-compose run --rm web bash -c "/data/symlink.sh && /data/src/add-test-users"

bash:
	docker-compose run --rm web bash

bashtests:
	docker-compose run --rm tests bash

behat:
	docker-compose run --rm tests bash -c "vendor/bin/behat"

behatappend:
	docker-compose run --rm tests bash -c "vendor/bin/behat --append-snippets"

bounce:
	docker-compose up -d web

clean:
	docker-compose kill
	docker-compose rm -f

composer:
	docker-compose run --rm tests bash -c "COMPOSER_ROOT_VERSION=$(VERSION) composer install --no-scripts"

composerupdate:
	docker-compose run --rm tests bash -c "COMPOSER_ROOT_VERSION=$(VERSION) composer update --no-scripts"

db:
	docker-compose up -d db

generatemodels: migratedb
	docker-compose run --rm web bash -c "/data/symlink.sh && /data/src/rebuildbasemodels.sh"

ldap:
	docker-compose up -d ldap

ldapadmin: ldap
	docker-compose up -d ldapadmin

ldapload: ldap
	docker-compose run --rm ldapload

migratedb: db
	docker-compose run --rm web bash -c "/data/symlink.sh && whenavail db 3306 60 /data/src/yii migrate --interactive=0"

migratetestdb: testdb
	docker-compose run --rm tests bash -c "whenavail testdb 3306 60 /data/src/yii migrate --interactive=0"

migration:
	docker-compose run --rm web bash -c "/data/symlink.sh && /data/src/yii migrate/create $(NAME)"

phpunit:
	docker-compose run --rm tests bash -c "cd src/tests && ../../vendor/bin/phpunit ."

ps:
	docker-compose ps

rmdb:
	docker-compose kill db
	docker-compose rm -f db

rmldap:
	docker-compose kill ldap
	docker-compose rm -f ldap

rmtestdb:
	docker-compose kill testdb
	docker-compose rm -f testdb

test: composer rmtestdb rmldap testdb ldap migratetestdb ldapload behat phpunit

testdb:
	docker-compose up -d testdb

web: db migratedb
	docker-compose up -d web
