# Makefile for building scoring example problems archives

PROBLEMS = addone fibonacci

.PHONY: all clean $(PROBLEMS)

all: $(PROBLEMS)

$(PROBLEMS):
	@echo "Building $@.zip..."
	@cd $@ && zip -r ../$@.zip . -x "*.swp" -x "*~"

clean:
	rm -f $(addsuffix .zip,$(PROBLEMS))
