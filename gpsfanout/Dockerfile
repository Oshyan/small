FROM python:3.12-alpine

WORKDIR /app
COPY fanout.py /app/fanout.py

RUN addgroup -S fanout && adduser -S -G fanout fanout \
    && mkdir -p /data \
    && chown -R fanout:fanout /data /app

USER fanout

EXPOSE 8080
CMD ["python", "/app/fanout.py"]
