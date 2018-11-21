MageSuite Page Cache Warmer - Crawler Library
=============================================

This is a standalone library for the cache warming crawler.

As a library it's an abstract component that is not runnable in itself.

It is magento-agnostic (dependency-wise) in order to be able to reuse
it in different deployments.

There are at least two scenarios for this:
    - It may be used in a magento command, thus it needs whole codebase
      of the project to be ran and also would be ran on the same instance
      as the application.
    - It may be used as standalone application and deployed to any server.

Any queue adapter may be implemented, for now a DB-backed pseudo-queue is used
as we do not expect high concurrency or load and this simplifies deployment.

See `creativestyle/magesuite-page-cache-warmer-crawler` magento extension
that integrates this library as a magento CLI command.

The main extension for managing the warmup priorities via admin and filling
the queue with new jobs is `creativestyle/magesuite-page-cache-warmer`.