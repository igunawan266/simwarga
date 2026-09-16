.PHONY: help build up down restart logs migrate seed migrate-rollback tinker test

# Colors for output
GREEN := \033[0;32m
YELLOW := \033[0;33m
RED := \033[0;31m
NC := \033[0m # No Color

help: ## Show this help message
	@echo "$(GREEN)SIMWarga Development Commands$(NC)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "$(YELLOW)%-15s$(NC) %s\n", $$1, $$2}'

build: ## Build Docker images
	@echo "$(GREEN)Building Docker images...$(NC)"
	docker-compose build

up: ## Start all containers
	@echo "$(GREEN)Starting containers...$(NC)"
	docker-compose up -d
	@echo "$(GREEN)✓ Containers started!$(NC)"
	@echo "   Application: http://localhost"
	@echo "   Database: localhost:3306"
	@echo "   Redis: localhost:6379"

down: ## Stop all containers
	@echo "$(GREEN)Stopping containers...$(NC)"
	docker-compose down

restart: ## Restart all containers
	@echo "$(GREEN)Restarting containers...$(NC)"
	docker-compose restart
	@echo "$(GREEN)✓ Containers restarted!$(NC)"

logs: ## View container logs
	docker-compose logs -f

logs-app: ## View app container logs
	docker-compose logs -f app

logs-nginx: ## View Nginx logs
	docker-compose logs -f nginx

logs-db: ## View database logs
	docker-compose logs -f db

ps: ## List running containers
	docker-compose ps

migrate: ## Run database migrations
	@echo "$(GREEN)Running migrations...$(NC)"
	docker-compose exec app php artisan migrate

migrate-rollback: ## Rollback database migrations
	@echo "$(YELLOW)Rolling back migrations...$(NC)"
	docker-compose exec app php artisan migrate:rollback

seed: ## Seed database with default data
	@echo "$(GREEN)Seeding database...$(NC)"
	docker-compose exec app php artisan db:seed

migrate-fresh: ## Drop all tables and re-run migrations
	@echo "$(RED)⚠️  This will delete all data!$(NC)"
	docker-compose exec app php artisan migrate:fresh

cache-clear: ## Clear all caches
	@echo "$(GREEN)Clearing cache...$(NC)"
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear

tinker: ## Start Laravel Tinker
	@echo "$(GREEN)Starting Tinker...$(NC)"
	docker-compose exec app php artisan tinker

test: ## Run application tests
	@echo "$(GREEN)Running tests...$(NC)"
	docker-compose exec app php artisan test

test-coverage: ## Run tests with coverage report
	@echo "$(GREEN)Running tests with coverage...$(NC)"
	docker-compose exec app php artisan test --coverage

install: ## Install composer dependencies
	@echo "$(GREEN)Installing dependencies...$(NC)"
	docker-compose exec app composer install

optimize: ## Optimize application for production
	@echo "$(GREEN)Optimizing application...$(NC)"
	docker-compose exec app php artisan optimize
	docker-compose exec app php artisan config:cache
	docker-compose exec app php artisan route:cache

queue-work: ## Start queue worker
	@echo "$(GREEN)Starting queue worker...$(NC)"
	docker-compose exec app php artisan queue:work

shell: ## Access app container shell
	@echo "$(GREEN)Entering container shell...$(NC)"
	docker-compose exec app sh

bash: ## Access app container bash
	@echo "$(GREEN)Entering container bash...$(NC)"
	docker-compose exec app bash

db-shell: ## Access database shell
	@echo "$(GREEN)Entering database shell...$(NC)"
	docker-compose exec db mysql -u simwarga_user -p simwarga_db

backup: ## Create database backup
	@echo "$(GREEN)Creating database backup...$(NC)"
	docker-compose exec db mysqldump -u simwarga_user -p simwarga_db > backup_$(shell date +%Y%m%d_%H%M%S).sql
	@echo "$(GREEN)✓ Backup created!$(NC)"

restore: ## Restore database from backup (usage: make restore FILE=backup_20260915_120000.sql)
	@echo "$(GREEN)Restoring database from $(FILE)...$(NC)"
	docker-compose exec db mysql -u simwarga_user -p simwarga_db < $(FILE)
	@echo "$(GREEN)✓ Database restored!$(NC)"

clean: ## Clean up Docker volumes and containers
	@echo "$(RED)⚠️  This will delete all containers and volumes!$(NC)"
	docker-compose down -v
	@echo "$(GREEN)✓ Cleaned!$(NC)"

setup: build up migrate ## Complete setup (build, up, migrate)
	@echo "$(GREEN)✓ Setup complete!$(NC)"
	@echo "   Application: http://localhost"

.DEFAULT_GOAL := help
