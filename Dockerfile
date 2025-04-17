# FROM php:8.2-cli
# COPY . /usr/src/myapp
# WORKDIR /usr/src/myapp
# EXPOSE 8000
# # RUN apt-get update && apt-get install -y php-mysql 
# # Install PDO MySQL extension
# # RUN docker-php-ext-install pdo pdo_mysql
# ENV DB_HOST="database-12.czypqm3ejt86.us-east-1.rds.amazonaws.com"
# ENV DB_NAME="cloud_project"
# ENV DB_USER="admin"
# ENV DB_PASSWORD="password"
# CMD [ "php", "-S", "0.0.0.0:8000" ]

# Use the PHP-CLI image (Debian-based or Alpine)
FROM php:8.3.20-cli

# Install system dependencies and the `pdo_mysql` extension
RUN apt-get update
RUN apt-get install -y libmariadb-dev
RUN docker-php-ext-install pdo pdo_mysql mysqli
# Copy your PHP files into the container
WORKDIR /app
COPY ./server.php /app

# Set the working directory to your app's root

ENV DB_HOST="csci-5409-project-rdsfc5e82b.czypqm3ejt86.us-east-1.rds.amazonaws.com"
ENV DB_USER="admin"
ENV DB_PASSWORD="password"
ENV DB_NAME="cloud_project"

# Expose port 8000 for the PHP development server
EXPOSE 80

# Start the PHP built-in server on all interfaces (0.0.0.0)
CMD ["php", "-S", "0.0.0.0:8000"]
