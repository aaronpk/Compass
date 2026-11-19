#!/bin/bash
export projectName='compass'

export dbName=${projectName}
export dbUser='user'
export dbPassword='user'


export portWebserver='80'
export portDatabase='3306'


docker-compose -f docker-compose.yml -p ${projectName} build

