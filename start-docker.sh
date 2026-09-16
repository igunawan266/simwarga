#!/bin/bash

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${GREEN}🚀 SIMWarga Docker Setup${NC}\n"

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker is not installed!${NC}"
    echo "Please install Docker from https://www.docker.com/products/docker-desktop"
    exit 1
fi

# Check if Docker Compose is installed
if ! command -v docker-compose &> /dev/null; then
    echo -e "${RED}❌ Docker Compose is not installed!${NC}"
    echo "Please install Docker Compose from https://docs.docker.com/compose/install/"
    exit 1
fi

echo -e "${GREEN}✓ Docker and Docker Compose are installed${NC}\n"

# Check if .env exists
if [ ! -f .env ]; then
    echo -e "${YELLOW}⚠️  .env file not found. Creating from .env.example...${NC}"
    cp .env.example .env
    echo -e "${GREEN}✓ .env file created${NC}"
    echo -e "${YELLOW}⚠️  Please update .env with your configuration${NC}\n"
fi

# Ask for action
echo -e "${YELLOW}Select action:${NC}"
echo "1) Build and start containers"
echo "2) Start existing containers"
echo "3) Stop containers"
echo "4) View logs"
echo "5) Run migrations"
echo "6) Access shell"
echo "7) Exit"
echo ""
read -p "Enter your choice (1-7): " choice

case $choice in
    1)
        echo -e "\n${GREEN}Building and starting containers...${NC}"
        docker-compose build
        docker-compose up -d
        echo -e "\n${GREEN}✓ Containers are running!${NC}"
        echo -e "   Application: http://localhost"
        echo -e "   Database: localhost:3306"
        echo -e "   Redis: localhost:6379"

        # Wait for containers to be ready
        echo -e "\n${YELLOW}⏳ Waiting for services to be ready...${NC}"
        sleep 10

        # Run migrations
        read -p "Run migrations now? (y/n): " run_migrations
        if [ "$run_migrations" = "y" ] || [ "$run_migrations" = "Y" ]; then
            docker-compose exec -T app php artisan migrate --force
            echo -e "${GREEN}✓ Migrations completed!${NC}"
        fi
        ;;

    2)
        echo -e "\n${GREEN}Starting containers...${NC}"
        docker-compose up -d
        echo -e "${GREEN}✓ Containers started!${NC}"
        echo -e "   Application: http://localhost"
        ;;

    3)
        echo -e "\n${YELLOW}Stopping containers...${NC}"
        docker-compose down
        echo -e "${GREEN}✓ Containers stopped!${NC}"
        ;;

    4)
        echo -e "\n${GREEN}Viewing logs (press Ctrl+C to exit)...${NC}"
        docker-compose logs -f
        ;;

    5)
        echo -e "\n${GREEN}Running migrations...${NC}"
        docker-compose exec app php artisan migrate
        echo -e "${GREEN}✓ Migrations completed!${NC}"
        ;;

    6)
        echo -e "\n${GREEN}Accessing application shell...${NC}"
        docker-compose exec app sh
        ;;

    7)
        echo -e "${GREEN}Goodbye!${NC}"
        exit 0
        ;;

    *)
        echo -e "${RED}Invalid choice!${NC}"
        exit 1
        ;;
esac

echo ""
echo -e "${GREEN}Done!${NC}"
echo -e "${YELLOW}Useful commands:${NC}"
echo "  make help          - Show all available commands"
echo "  make logs          - View logs"
echo "  make migrate       - Run migrations"
echo "  make tinker        - Access Laravel Tinker"
echo "  make test          - Run tests"
echo "  make down          - Stop containers"
